<?php

final class PhabricatorApplicationSearchController
  extends PhabricatorSearchBaseController {

  private $searchEngine;
  private $navigation;
  private $queryKey;
  private $preface;

  public function setPreface($preface) {
    $this->preface = $preface;
    return $this;
  }

  public function getPreface() {
    return $this->preface;
  }

  public function setQueryKey($query_key) {
    $this->queryKey = $query_key;
    return $this;
  }

  protected function getQueryKey() {
    return $this->queryKey;
  }

  public function setNavigation(AphrontSideNavFilterView $navigation) {
    $this->navigation = $navigation;
    return $this;
  }

  protected function getNavigation() {
    return $this->navigation;
  }

  public function setSearchEngine(
    PhabricatorApplicationSearchEngine $search_engine) {
    $this->searchEngine = $search_engine;
    return $this;
  }

  protected function getSearchEngine() {
    return $this->searchEngine;
  }

  protected function validateDelegatingController() {
    $parent = $this->getDelegatingController();

    if (!$parent) {
      throw new Exception(
        pht('You must delegate to this controller, not invoke it directly.'));
    }

    $engine = $this->getSearchEngine();
    if (!$engine) {
      throw new PhutilInvalidStateException('setEngine');
    }

    $engine->setViewer($this->getRequest()->getUser());

    $parent = $this->getDelegatingController();
  }

  public function processRequest() {
    $this->validateDelegatingController();

    $key = $this->getQueryKey();
    if ($key == 'edit') {
      return $this->processEditRequest();
    } else {
      return $this->processSearchRequest();
    }
  }

  private function processSearchRequest() {
    $parent = $this->getDelegatingController();
    $request = $this->getRequest();
    $user = $request->getUser();
    $engine = $this->getSearchEngine();
    $nav = $this->getNavigation();
    if (!$nav) {
      $nav = $this->buildNavigation();
    }

    if ($request->isFormPost()) {
      $saved_query = $engine->buildSavedQueryFromRequest($request);
      $engine->saveQuery($saved_query);
      return id(new AphrontRedirectResponse())->setURI(
        $engine->getQueryResultsPageURI($saved_query->getQueryKey()).'#R');
    }

    $named_query = null;
    $run_query = true;
    $query_key = $this->queryKey;
    if ($this->queryKey == 'advanced') {
      $run_query = false;
      $query_key = $request->getStr('query');
    } else if (!strlen($this->queryKey)) {
      $found_query_data = false;

      if ($request->isHTTPGet() || $request->isQuicksand()) {
        // If this is a GET request and it has some query data, don't
        // do anything unless it's only before= or after=. We'll build and
        // execute a query from it below. This allows external tools to build
        // URIs like "/query/?users=a,b".
        $pt_data = $request->getPassthroughRequestData();

        $exempt = array(
          'before' => true,
          'after' => true,
          'nux' => true,
        );

        foreach ($pt_data as $pt_key => $pt_value) {
          if (isset($exempt[$pt_key])) {
            continue;
          }

          $found_query_data = true;
          break;
        }
      }

      if (!$found_query_data) {
        // Otherwise, there's no query data so just run the user's default
        // query for this application.
        $query_key = head_key($engine->loadEnabledNamedQueries());
      }
    }

    if ($engine->isBuiltinQuery($query_key)) {
      $saved_query = $engine->buildSavedQueryFromBuiltin($query_key);
      $named_query = idx($engine->loadEnabledNamedQueries(), $query_key);
    } else if ($query_key) {
      $saved_query = id(new PhabricatorSavedQueryQuery())
        ->setViewer($user)
        ->withQueryKeys(array($query_key))
        ->executeOne();

      if (!$saved_query) {
        return new Aphront404Response();
      }

      $named_query = idx($engine->loadEnabledNamedQueries(), $query_key);
    } else {
      $saved_query = $engine->buildSavedQueryFromRequest($request);

      // Save the query to generate a query key, so "Save Custom Query..." and
      // other features like Maniphest's "Export..." work correctly.
      $engine->saveQuery($saved_query);
    }

    $nav->selectFilter(
      'query/'.$saved_query->getQueryKey(),
      'query/advanced');

    $form = id(new AphrontFormView())
      ->setUser($user)
      ->setAction($request->getPath());

    $engine->buildSearchForm($form, $saved_query);

    $errors = $engine->getErrors();
    if ($errors) {
      $run_query = false;
    }

    $submit = id(new AphrontFormSubmitControl())
      ->setValue(pht('Execute Query'));

    if ($run_query && !$named_query && $user->isLoggedIn()) {
      $submit->addCancelButton(
        '/search/edit/'.$saved_query->getQueryKey().'/',
        pht('Save Custom Query...'));
    }

    // TODO: A "Create Dashboard Panel" action goes here somewhere once
    // we sort out T5307.

    $form->appendChild($submit);
    $body = array();

    if ($this->getPreface()) {
      $body[] = $this->getPreface();
    }

    if ($named_query) {
      $title = $named_query->getQueryName();
    } else {
      $title = pht('Advanced Search');
    }

    $header = id(new PHUIHeaderView())
      ->setHeader($title)
      ->setProfileHeader(true);

    $box = id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->addClass('application-search-results');

    if ($run_query || $named_query) {
      $box->setShowHide(
        pht('Edit Query'),
        pht('Hide Query'),
        $form,
        $this->getApplicationURI('query/advanced/?query='.$query_key),
        (!$named_query ? true : false));
    } else {
      $box->setForm($form);
    }

    $body[] = $box;
    $more_crumbs = null;

    if ($run_query) {
      $exec_errors = array();

      $box->setAnchor(
        id(new PhabricatorAnchorView())
          ->setAnchorName('R'));

      try {
        $engine->setRequest($request);

        $query = $engine->buildQueryFromSavedQuery($saved_query);

        $pager = $engine->newPagerForSavedQuery($saved_query);
        $pager->readFromRequest($request);

        $objects = $engine->executeQuery($query, $pager);

        $force_nux = $request->getBool('nux');
        if (!$objects || $force_nux) {
          $nux_view = $this->renderNewUserView($engine, $force_nux);
        } else {
          $nux_view = null;
        }

        if ($nux_view) {
          $box->appendChild($nux_view);
        } else {
          $list = $engine->renderResults($objects, $saved_query);

          if (!($list instanceof PhabricatorApplicationSearchResultView)) {
            throw new Exception(
              pht(
                'SearchEngines must render a "%s" object, but this engine '.
                '(of class "%s") rendered something else.',
                'PhabricatorApplicationSearchResultView',
                get_class($engine)));
          }

          if ($list->getActions()) {
            foreach ($list->getActions() as $action) {
              $header->addActionLink($action);
            }
          }

          if ($list->getObjectList()) {
            $box->setObjectList($list->getObjectList());
          }
          if ($list->getTable()) {
            $box->setTable($list->getTable());
          }
          if ($list->getInfoView()) {
            $box->setInfoView($list->getInfoView());
          }
          if ($list->getContent()) {
            $box->appendChild($list->getContent());
          }

          $result_header = $list->getHeader();
          if ($result_header) {
            $box->setHeader($result_header);
          }

          $more_crumbs = $list->getCrumbs();

          if ($pager->willShowPagingControls()) {
            $pager_box = id(new PHUIBoxView())
              ->setColor(PHUIBoxView::GREY)
              ->addClass('application-search-pager')
              ->appendChild($pager);
            $body[] = $pager_box;
          }
        }
      } catch (PhabricatorTypeaheadInvalidTokenException $ex) {
        $exec_errors[] = pht(
          'This query specifies an invalid parameter. Review the '.
          'query parameters and correct errors.');
      }

      // The engine may have encountered additional errors during rendering;
      // merge them in and show everything.
      foreach ($engine->getErrors() as $error) {
        $exec_errors[] = $error;
      }

      $errors = $exec_errors;
    }

    if ($errors) {
      $box->setFormErrors($errors, pht('Query Errors'));
    }

    $crumbs = $parent
      ->buildApplicationCrumbs()
      ->setBorder(true);

    if ($more_crumbs) {
      $query_uri = $engine->getQueryResultsPageURI($saved_query->getQueryKey());
      $crumbs->addTextCrumb($title, $query_uri);

      foreach ($more_crumbs as $crumb) {
        $crumbs->addCrumb($crumb);
      }
    } else {
      $crumbs->addTextCrumb($title);
    }

    require_celerity_resource('application-search-view-css');

    return $this->newPage()
      ->setApplicationMenu($this->buildApplicationMenu())
      ->setTitle(pht('Query: %s', $title))
      ->setCrumbs($crumbs)
      ->setNavigation($nav)
      ->addFrameClass('application-search-view')
      ->appendChild($body);
  }

  private function processEditRequest() {
    $parent = $this->getDelegatingController();
    $request = $this->getRequest();
    $user = $request->getUser();
    $engine = $this->getSearchEngine();

    $nav = $this->getNavigation();
    if (!$nav) {
      $nav = $this->buildNavigation();
    }

    $named_queries = $engine->loadAllNamedQueries();

    $list_id = celerity_generate_unique_node_id();

    $list = new PHUIObjectItemListView();
    $list->setUser($user);
    $list->setID($list_id);

    Javelin::initBehavior(
      'search-reorder-queries',
      array(
        'listID' => $list_id,
        'orderURI' => '/search/order/'.get_class($engine).'/',
      ));

    foreach ($named_queries as $named_query) {
      $class = get_class($engine);
      $key = $named_query->getQueryKey();

      $item = id(new PHUIObjectItemView())
        ->setHeader($named_query->getQueryName())
        ->setHref($engine->getQueryResultsPageURI($key));

      if ($named_query->getIsBuiltin() && $named_query->getIsDisabled()) {
        $icon = 'fa-plus';
      } else {
        $icon = 'fa-times';
      }

      $item->addAction(
        id(new PHUIListItemView())
          ->setIcon($icon)
          ->setHref('/search/delete/'.$key.'/'.$class.'/')
          ->setWorkflow(true));

      if ($named_query->getIsBuiltin()) {
        if ($named_query->getIsDisabled()) {
          $item->addIcon('fa-times lightgreytext', pht('Disabled'));
          $item->setDisabled(true);
        } else {
          $item->addIcon('fa-lock lightgreytext', pht('Builtin'));
        }
      } else {
        $item->addAction(
          id(new PHUIListItemView())
            ->setIcon('fa-pencil')
            ->setHref('/search/edit/'.$key.'/'));
      }

      $item->setGrippable(true);
      $item->addSigil('named-query');
      $item->setMetadata(
        array(
          'queryKey' => $named_query->getQueryKey(),
        ));

      $list->addItem($item);
    }

    $list->setNoDataString(pht('No saved queries.'));

    $crumbs = $parent
      ->buildApplicationCrumbs()
      ->addTextCrumb(pht('Saved Queries'), $engine->getQueryManagementURI())
      ->setBorder(true);

    $nav->selectFilter('query/edit');

    $header = id(new PHUIHeaderView())
      ->setHeader(pht('Saved Queries'))
      ->setProfileHeader(true);

    $box = id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->setObjectList($list)
      ->addClass('application-search-results');

    require_celerity_resource('application-search-view-css');

    return $this->newPage()
      ->setApplicationMenu($this->buildApplicationMenu())
      ->setTitle(pht('Saved Queries'))
      ->setCrumbs($crumbs)
      ->setNavigation($nav)
      ->addFrameClass('application-search-view')
      ->appendChild($box);
  }

  public function buildApplicationMenu() {
    $menu = $this->getDelegatingController()
      ->buildApplicationMenu();

    if ($menu instanceof PHUIApplicationMenuView) {
      $menu->setSearchEngine($this->getSearchEngine());
    }

    return $menu;
  }

  private function buildNavigation() {
    $viewer = $this->getViewer();
    $engine = $this->getSearchEngine();

    $nav = id(new AphrontSideNavFilterView())
      ->setUser($viewer)
      ->setBaseURI(new PhutilURI($this->getApplicationURI()));

    $engine->addNavigationItems($nav->getMenu());

    return $nav;
  }

  private function renderNewUserView(
    PhabricatorApplicationSearchEngine $engine,
    $force_nux) {

    // Don't render NUX if the user has clicked away from the default page.
    if (strlen($this->getQueryKey())) {
      return null;
    }

    // Don't put NUX in panels because it would be weird.
    if ($engine->isPanelContext()) {
      return null;
    }

    // Try to render the view itself first, since this should be very cheap
    // (just returning some text).
    $nux_view = $engine->renderNewUserView();

    if (!$nux_view) {
      return null;
    }

    $query = $engine->newQuery();
    if (!$query) {
      return null;
    }

    // Try to load any object at all. If we can, the application has seen some
    // use so we just render the normal view.
    if (!$force_nux) {
      $object = $query
        ->setViewer(PhabricatorUser::getOmnipotentUser())
        ->setLimit(1)
        ->execute();
      if ($object) {
        return null;
      }
    }

    return $nux_view;
  }


}
