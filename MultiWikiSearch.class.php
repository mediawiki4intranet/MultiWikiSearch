<?php

/* MultiWikiSearch extension class
 * Copyright (c) 2013, Andrey Krasilnikov <z010107@gmail.com>
 * License: GPLv3.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

class MultiWikiSearch extends SpecialPage {

    protected $profile;

    /// Search engine
    protected $searchEngine;

    /// For links
    protected $extraParams = array();

    /// No idea, apparently used by some other classes
    protected $mPrefix;

    // Wiki list from request
    protected $wikilist;

    // Current user
    protected $user;

    protected $wiki_search_result;

    const NAMESPACES_CURRENT = 'sense';

    const MWS_CACHE = 1;

    public function __construct() {
        parent::__construct( 'MultiWikiSearch' );
    }

    /**
     * Entry point
     *
     * @param $par String or null
     */
    public function execute( $par ) {
        global $wgRequest, $wgUser, $wgOut;

        $this->setHeaders();
        $this->outputHeader();
        $wgOut->allowClickjacking();
        $wgOut->addModuleStyles( 'mediawiki.special' );
        $wgOut->addModuleStyles( 'MultiWikiSearch.style' );
        $wgOut->addModules( 'MultiWikiSearch.script' );

        // Strip underscores from title parameter; most of the time we'll want
        // text form here. But don't strip underscores from actual text params!
        $titleParam = str_replace( '_', ' ', $par );

        // Fetch the search term
        $search = str_replace( "\n", " ", $wgRequest->getText( 'multiwikisearch', $titleParam ) );

        $this->load( $wgRequest, $wgUser );

        if ( $wgRequest->getVal( 'fulltext' )
            || !is_null( $wgRequest->getVal( 'offset' ) )
            || !is_null( $wgRequest->getVal( 'searchx' ) ) )
        {
            $this->showResults( $search );
        } else {
            $this->goResult( $search );
        }
    }

    /**
     * Set up basic search parameters from the request and user settings.
     * Typically you'll pass $wgRequest and $wgUser.
     *
     * @param $request WebRequest
     * @param $user User
     */
    public function load( &$request, &$user ) {
        list( $this->limit, $this->offset ) = $request->getLimitOffset( 20, 'searchlimit' );
        $this->mPrefix = $request->getVal( 'prefix', '' );
        $this->user = array('mId' => $user->mId, 'mToken' => $user->mToken);

        # Extract manually requested namespaces
        $nslist = $this->powerSearch( $request );
        $this->profile = $profile = $request->getVal( 'profile', null );
        $profiles = $this->getSearchProfiles();
        if ( $profile === null) {
            // BC with old request format
            $this->profile = 'advanced';
            if ( count( $nslist ) ) {
                foreach( $profiles as $key => $data ) {
                    if ( $nslist === $data['namespaces'] && $key !== 'advanced') {
                        $this->profile = $key;
                    }
                }
                $this->namespaces = $nslist;
            } else {
                $this->namespaces = SearchEngine::userNamespaces( $user );
            }
        } elseif ( $profile === 'advanced' ) {
            $this->namespaces = $nslist;
        } else {
            if ( isset( $profiles[$profile]['namespaces'] ) ) {
                $this->namespaces = $profiles[$profile]['namespaces'];
            } else {
                // Unknown profile requested
                $this->profile = 'default';
                $this->namespaces = $profiles['default']['namespaces'];
            }
        }

        // Redirects defaults to true, but we don't know whether it was ticked of or just missing
        $default = $request->getBool( 'profile' ) ? 0 : 1;
        $this->searchRedirects = $request->getBool( 'redirs', $default ) ? 1 : 0;
        $this->sk = $this->getSkin();
        $this->didYouMeanHtml = ''; # html of did you mean... link
        $this->fulltext = $request->getVal('fulltext');
        $this->wikilist = $request->getArray('wikilist');
    }

    /**
     * If an exact title match can be found, jump straight ahead to it.
     *
     * @param $term String
     */
    public function goResult( $term ) {
        global $wgOut;
        $this->setupPage( $term );

        # Try to go to page as entered.
        $t = Title::newFromText( $term );
        # If the string cannot be used to create a title
        if( is_null( $t ) ) {
            return $this->showResults( $term );
        }

        $t = Title::newFromText( $term );
        if( !is_null( $t ) ) {
            global $wgGoToEdit;
            wfRunHooks( 'SpecialSearchNogomatch', array( &$t ) );
            wfDebugLog( 'nogomatch', $t->getText(), false );

            # If the feature is enabled, go straight to the edit page
            if( $wgGoToEdit ) {
                $wgOut->redirect( $t->getFullURL( array( 'action' => 'edit' ) ) );
                return;
            }
        }
        return $this->showResults( $term );
    }

    protected function requestApiSearch( $term ) {
        global $wgSitename, $wgServer, $wgScriptPath, $wgMiltiWikiSearchOption, $wgSharedDB;


        if (!empty($this->wikilist) && !empty($term) && isset($wgMiltiWikiSearchOption['wiki'])) {
            $cache = wfGetCache(CACHE_DB);
            $cache_key = md5(serialize(array('user' => $this->user, 'term' => $term, 'wikilist' => $this->wikilist, 'namespaces' => $this->namespaces)));

            if ($data_search_from_wikis_cache = $cache->get($cache_key)) {
                $this->wiki_search_result = json_decode($data_search_from_wikis_cache);
            } else {
                $api_url = '/api.php?format=json&action=query&list=search&srsearch='.
                    urlencode($term).'&srprop=snippet|score|size|wordcount|timestamp&srlimit=50&sroffset=0'.
                    (!empty($this->namespaces) ? '&srnamespace='.(implode('|', $this->namespaces)) : '');

                $data_search_from_wikis = [];
                foreach($this->wikilist as $wiki_name) {
                    $wiki_rq_url = '';
                    if ($wiki_name == $wgSitename) {
                        $wiki_rq_url = $wgServer.$wgScriptPath.$api_url;
                    } else {
                        $wiki_rq_url = $wgMiltiWikiSearchOption['wiki'][$wiki_name].$api_url;
                    }

                    $options = [ 'header' => array('set-cookie: '.$wgSharedDB.'UserID'.'='.$this->user['mId'].'; Max-Age=3600; Version=1', 'set-cookie: '.$wgSharedDB.'Token'.'='.$this->user['mToken'].'; Max-Age=3600; Version=1') ];

                    $data_content = HTTP::get($wiki_rq_url, $options);

                    if (!empty($data_content)) {
                        $obj_data_content = json_decode($data_content);
                        if (isset($obj_data_content->query->search)) {
                            foreach($obj_data_content->query->search as $item) {
                                $item->wiki_name = $wiki_name;
                                $data_search_from_wikis[] = $item;
                            }
                        }
                    }
                }

                // sorting result by score
                usort($data_search_from_wikis, function ($a, $b) {
                        if (!isset($a->score))
                            return 0;
                        if ($a->score == $b->score) {
                            return 0;
                        }
                        return ($a->score > $b->score) ? -1 : 1;
                    }
                );

                $cache->set($cache_key, json_encode($data_search_from_wikis), self::MWS_CACHE);
                $this->wiki_search_result = $data_search_from_wikis;
            }
        }
    }


    /**
     * @param $term String
     */
    public function showResults( $term ) {
        global $wgOut, $wgContLang, $wgScript;
        wfProfileIn( __METHOD__ );

        // Request API:Search in all selected wiki and getting full search list.
        // Store it in cache. Cache time limit 1 hour. I think it will be enough.
        $this->requestApiSearch( $term );

        $t = Title::newFromText( $term );

        // start rendering the page
        $wgOut->addHtml(
            Xml::openElement(
                'form',
                array(
                   'id' => ( $this->profile === 'advanced' ? 'powersearch' : 'search' ),
                   'method' => 'get',
                   'action' => $wgScript
                )
            )
        );
        $wgOut->addHtml(
            Xml::openElement( 'table', array( 'id'=>'mw-search-top-table', 'border'=>0, 'cellpadding'=>0, 'cellspacing'=>0 ) ) .
                Xml::openElement( 'tr' ) .
                    Xml::openElement( 'td' ) . "\n" .
                    $this->shortDialog( $term ) .
                    Xml::closeElement('td') .
                Xml::closeElement('tr') .
            Xml::closeElement('table')
        );

        $wgOut->addHTML( $this->showWikiList() );

        $filePrefix = $wgContLang->getFormattedNsText(NS_FILE).':';
        if( trim( $term ) === '' || $filePrefix === trim( $term ) ) {
            $wgOut->addHTML( $this->formHeader( $term, 0, 0 ) );
            $wgOut->addHtml( $this->getProfileForm( $this->profile, $term ) );
            $wgOut->addHTML( '</form>' );
            // Empty query -- straight view of search form
            wfProfileOut( __METHOD__ );
            return;
        }

        // Total initial query matches (possible false positives)
        // Divide full array to pages and calculate what page selected
        $wiki_search_result_by_pages = [];
        if (!empty($this->wiki_search_result)) {
            $wiki_search_result_by_pages = array_chunk($this->wiki_search_result, $this->limit, true);
        }
        $page_number = ($this->offset > 0 && $this->limit > 0) ? $this->offset / $this->limit : 0;
        $selected_wiki_search_result = isset($wiki_search_result_by_pages[$page_number]) ? $wiki_search_result_by_pages[$page_number] : [];

        // Count  item for page and all result
        $num = count($selected_wiki_search_result);
        $totalRes = count($this->wiki_search_result);

       // show number of results and current offset
       $wgOut->addHTML( $this->formHeader( $term, $num, $totalRes ) );
       $wgOut->addHtml( $this->getProfileForm( $this->profile, $term ) );

       $wgOut->addHtml( Xml::closeElement( 'form' ) );
       $wgOut->addHtml( "<div class='searchresults'>" );

       // prev/next links
       if( $num || $this->offset ) {
           // Show the create link ahead
           $this->showCreateLink( $t );
           $prevnext = wfViewPrevNext( $this->offset, $this->limit,
               SpecialPage::getTitleFor( 'MultiWikiSearch' ),
               wfArrayToCGI( $this->powerSearchOptions(), array( 'multiwikisearch' => $term, 'fulltext' => wfMsg( 'search' ), 'wikilist' => $this->wikilist ) ),
               $num < $this->limit
           );
           $wgOut->addHTML( "<p class='mw-search-pager-top'>{$prevnext}</p>\n" );
       } else {
           wfRunHooks( 'SpecialSearchNoResults', array( $term ) );
       }

       $wgOut->parserOptions()->setEditSection( false );

        // show results
        if( $num > 0 ) {
            $wgOut->addHTML( $this->showMatches( $selected_wiki_search_result ) );
        }

        if( $num === 0 ) {
           $wgOut->wrapWikiMsg( "<p class=\"mw-search-nonefound\">\n$1</p>", array( 'search-nonefound', wfEscapeWikiText( $term ) ) );
           $this->showCreateLink( $t );
       }
       $wgOut->addHtml( "</div>" );


       if( $num || $this->offset ) {
           $wgOut->addHTML( "<p class='mw-search-pager-bottom'>{$prevnext}</p>\n" );
       }
       wfProfileOut( __METHOD__ );
    }

    protected function showCreateLink( $t ) {
        global $wgOut;

        // show direct page/create link if applicable

        // Check DBkey !== '' in case of fragment link only.
        if( is_null( $t ) || $t->getDBkey() === '' ) {
            // invalid title
            // preserve the paragraph for margins etc...
            $this->getOutput()->addHtml( '<p></p>' );
            return;
        }

        if( $t->isKnown() ) {
            $messageName = 'searchmenu-exists';
        } elseif( $t->userCan( 'create' ) ) {
            $messageName = 'searchmenu-new';
        } else {
            $messageName = 'searchmenu-new-nocreate';
        }
        $params = array( $messageName, wfEscapeWikiText( $t->getPrefixedText() ) );
        wfRunHooks( 'SpecialSearchCreateLink', array( $t, &$params ) );

        // Extensions using the hook might still return an empty $messageName
        if( $messageName ) {
            $this->getOutput()->wrapWikiMsg( "<p class=\"mw-search-createlink\">\n$1</p>", $params );
        } else {
            // preserve the paragraph for margins etc...
            $wgOut->addHtml( '<p></p>' );
        }
    }

    /**
     *
     */
    protected function setupPage( $term ) {
        global $wgOut;

        # Should advanced UI be used?
        $this->searchAdvanced = ($this->profile === 'advanced');
        if( strval( $term ) !== ''  ) {
            $wgOut->setPageTitle( wfMsg( 'searchresults') );
            $wgOut->setHTMLTitle( wfMsg( 'pagetitle', wfMsg( 'searchresults-title', $term ) ) );
        }
        // add javascript specific to special:search
        $wgOut->addModules( 'mediawiki.special.search' );
    }

    /**
     * Extract "power search" namespace settings from the request object,
     * returning a list of index numbers to search.
     *
     * @param $request WebRequest
     * @return Array
     */
    protected function powerSearch( &$request ) {
        $arr = array();
        foreach( SearchEngine::searchableNamespaces() as $ns => $name ) {
            if( $request->getCheck( 'ns' . $ns ) ) {
                $arr[] = $ns;
            }
        }

        return $arr;
    }

    /**
     * Reconstruct the 'power search' options for links
     *
     * @return Array
     */
    protected function powerSearchOptions() {
        $opt = array();
        $opt['redirs'] = $this->searchRedirects ? 1 : 0;
        if( $this->profile !== 'advanced' ) {
            $opt['profile'] = $this->profile;
        } else {
            foreach( $this->namespaces as $n ) {
                $opt['ns' . $n] = 1;
            }
        }
        return $opt + $this->extraParams;
    }

    /**
     * Show whole set of results
     *
     * @param $matches Simple array of objects from API:Search
     */
    protected function showMatches( &$matches ) {
        wfProfileIn( __METHOD__ );

        $out = Xml::openelement( 'ul', array( 'class' => 'mw-search-results' ) );
        foreach($matches as $result) {
            $out .= $this->showHit( $result );
        }
        $out .= Xml::closeElement( 'ul' );

        wfProfileOut( __METHOD__ );
        return $out;
    }

    /**
     * Format a single hit result
     *
     * @param $result Simple Object
     */
    protected function showHit( $result ) {
        global $wgLang, $wgSitename, $wgServer, $wgMiltiWikiSearchOption, $wgScriptPath;
        wfProfileIn( __METHOD__ );

        $title = $result->title;
        $titleSnippet = ($result->snippet != '') ? $result->snippet : null;

        $link = '<a href="'.($result->wiki_name != $wgSitename ? $wgMiltiWikiSearchOption['wiki'][$result->wiki_name] : $wgScriptPath).'/index.php?title='.$title.'" title="'.$title.'" '.($result->wiki_name != $wgSitename ? ' target="_blank"' : '').'>'.$result->wiki_name.': '.$title.'</a>';

        // format text extract
        $extract = "<div class='searchresult'>".$titleSnippet."</div>";

        // format score
        if( is_null( $result->score ) ) {
            // Search engine doesn't report scoring info
            $score = '';
        } else {
            $percent = sprintf( '%2.1f', $result->score * 100 );
            $score = wfMsg( 'search-result-score', $wgLang->formatNum( $percent ) )
                . ' - ';
        }

        // format description
        $byteSize = $result->size;
        $wordCount = $result->wordcount;
        $timestamp = $result->timestamp;
        $size = wfMsgExt(
            'search-result-size',
            array( 'parsemag', 'escape' ),
            $wgLang->formatSize( $byteSize ),
            $wgLang->formatNum( $wordCount )
        );

        $date = $wgLang->timeanddate( $timestamp );

        wfProfileOut( __METHOD__ );
        return "<li><div class='mw-search-result-heading'>{$link}</div> {$extract} \n" .
            "<div class='mw-search-result-data'>{$score}{$size} - {$date} </div>" .
            "</li>\n";

    }

    protected function getProfileForm( $profile, $term ) {
        // Hidden stuff
        $opts = array();
        $opts['redirs'] = $this->searchRedirects;
        $opts['profile'] = $this->profile;

        if ( $profile === 'advanced' ) {
            return $this->powerSearchBox( $term, $opts );
        } else {
            $form = '';
            wfRunHooks( 'SpecialSearchProfileForm', array( $this, &$form, $profile, $term, $opts ) );
            return $form;
        }
    }

    /**
     * Generates the power search box at [[Special:Search]]
     *
     * @param $term String: search term
     * @return String: HTML form
     */
    protected function powerSearchBox( $term, $opts ) {
        // Groups namespaces into rows according to subject
        $rows = array();
        foreach( SearchEngine::searchableNamespaces() as $namespace => $name ) {
            $subject = MWNamespace::getSubject( $namespace );
            if( !array_key_exists( $subject, $rows ) ) {
                $rows[$subject] = "";
            }
            $name = str_replace( '_', ' ', $name );
            if( $name == '' ) {
                $name = wfMsg( 'blanknamespace' );
            }
            $rows[$subject] .=
                Xml::openElement(
                    'td', array( 'style' => 'white-space: nowrap' )
                ) .
                    Xml::checkLabel(
                        $name,
                        "ns{$namespace}",
                        "mw-search-ns{$namespace}",
                        in_array( $namespace, $this->namespaces )
                    ) .
                    Xml::closeElement( 'td' );
        }
        $rows = array_values( $rows );
        $numRows = count( $rows );

        // Lays out namespaces in multiple floating two-column tables so they'll
        // be arranged nicely while still accommodating different screen widths
        $namespaceTables = '';
        for( $i = 0; $i < $numRows; $i += 4 ) {
            $namespaceTables .= Xml::openElement(
                'table',
                array( 'cellpadding' => 0, 'cellspacing' => 0, 'border' => 0 )
            );
            for( $j = $i; $j < $i + 4 && $j < $numRows; $j++ ) {
                $namespaceTables .= Xml::tags( 'tr', null, $rows[$j] );
            }
            $namespaceTables .= Xml::closeElement( 'table' );
        }

        $showSections = array( 'namespaceTables' => $namespaceTables );

        // Show redirects check only if backend supports it
        if( $this->getSearchEngine()->supports( 'list-redirects' ) ) {
            $showSections['redirects'] =
                Xml::checkLabel( wfMsg( 'powersearch-redir' ), 'redirs', 'redirs', $this->searchRedirects );
        }

        wfRunHooks( 'SpecialSearchPowerBox', array( &$showSections, $term, $opts ) );

        $hidden = '';
        unset( $opts['redirs'] );
        foreach( $opts as $key => $value ) {
            $hidden .= Html::hidden( $key, $value );
        }
        // Return final output
        return
            Xml::openElement(
                'fieldset',
                array( 'id' => 'mw-searchoptions', 'style' => 'margin:0em;' )
            ) .
            Xml::element( 'legend', null, wfMsg('powersearch-legend') ) .
            Xml::tags( 'h4', null, wfMsgExt( 'powersearch-ns', array( 'parseinline' ) ) ) .
            Xml::tags(
                'div',
                array( 'id' => 'mw-search-togglebox' ),
                Xml::label( wfMsg( 'powersearch-togglelabel' ), 'mw-search-togglelabel' ) .
                Xml::element(
                    'input',
                    array(
                        'type'=>'button',
                        'id' => 'mw-search-toggleall',
                        'value' => wfMsg( 'powersearch-toggleall' )
                    )
                ) .
                Xml::element(
                    'input',
                    array(
                        'type'=>'button',
                        'id' => 'mw-search-togglenone',
                        'value' => wfMsg( 'powersearch-togglenone' )
                    )
                )
            ) .
            Xml::element( 'div', array( 'class' => 'divider' ), '', false ) .
            implode( Xml::element( 'div', array( 'class' => 'divider' ), '', false ), $showSections ) .
            $hidden .
            Xml::closeElement( 'fieldset' );
    }

    protected function getSearchProfiles() {
        // Builds list of Search Types (profiles)
        $nsAllSet = array_keys( SearchEngine::searchableNamespaces() );

        $profiles = array(
            'default' => array(
                'message' => 'searchprofile-articles',
                'tooltip' => 'searchprofile-articles-tooltip',
                'namespaces' => SearchEngine::defaultNamespaces(),
                'namespace-messages' => SearchEngine::namespacesAsText(
                    SearchEngine::defaultNamespaces()
                ),
            ),
            'images' => array(
                'message' => 'searchprofile-images',
                'tooltip' => 'searchprofile-images-tooltip',
                'namespaces' => array( NS_FILE ),
            ),
            'help' => array(
                'message' => 'searchprofile-project',
                'tooltip' => 'searchprofile-project-tooltip',
                'namespaces' => SearchEngine::helpNamespaces(),
                'namespace-messages' => SearchEngine::namespacesAsText(
                    SearchEngine::helpNamespaces()
                ),
            ),
            'all' => array(
                'message' => 'searchprofile-everything',
                'tooltip' => 'searchprofile-everything-tooltip',
                'namespaces' => $nsAllSet,
            ),
            'advanced' => array(
                'message' => 'searchprofile-advanced',
                'tooltip' => 'searchprofile-advanced-tooltip',
                'namespaces' => self::NAMESPACES_CURRENT,
            )
        );

        wfRunHooks( 'SpecialSearchProfiles', array( &$profiles ) );

        foreach( $profiles as &$data ) {
            if ( !is_array( $data['namespaces'] ) ) continue;
            sort( $data['namespaces'] );
        }

        return $profiles;
    }

    protected function formHeader( $term, $resultsShown, $totalNum ) {
        global $wgLang;

        $out = Xml::openElement('div', array( 'class' =>  'mw-search-formheader' ) );

        $bareterm = $term;
        if( $this->startsWithImage( $term ) ) {
            // Deletes prefixes
            $bareterm = substr( $term, strpos( $term, ':' ) + 1 );
        }

        $profiles = $this->getSearchProfiles();

        // Outputs XML for Search Types
        $out .= Xml::openElement( 'div', array( 'class' => 'search-types' ) );
        $out .= Xml::openElement( 'ul' );
        foreach ( $profiles as $id => $profile ) {
            if ( !isset( $profile['parameters'] ) ) {
                $profile['parameters'] = array();
            }
            $profile['parameters']['profile'] = $id;

            $tooltipParam = isset( $profile['namespace-messages'] ) ?
                $wgLang->commaList( $profile['namespace-messages'] ) : null;
            $out .= Xml::tags(
                'li',
                array(
                    'class' => $this->profile === $id ? 'current' : 'normal'
                ),
                $this->makeSearchLink(
                    $bareterm,
                    array(),
                    wfMsg( $profile['message'] ),
                    wfMsg( $profile['tooltip'], $tooltipParam ),
                    $profile['parameters']
                )
            );
        }
        $out .= Xml::closeElement( 'ul' );
        $out .= Xml::closeElement('div') ;

        // Results-info
        if ( $resultsShown > 0 ) {
            if ( $totalNum > 0 ){
                $top = wfMsgExt( 'showingresultsheader', array( 'parseinline' ),
                    $wgLang->formatNum( $this->offset + 1 ),
                    $wgLang->formatNum( $this->offset + $resultsShown ),
                    $wgLang->formatNum( $totalNum ),
                    wfEscapeWikiText( $term ),
                    $wgLang->formatNum( $resultsShown )
                );
            } elseif ( $resultsShown >= $this->limit ) {
                $top = wfShowingResults( $this->offset, $this->limit );
            } else {
                $top =  wfMsgExt( 'showingresultsnum', array( 'parseinline' ),
                    $wgLang->formatNum( $this->limit ),
                    $wgLang->formatNum( $this->offset + 1 ),
                    $wgLang->formatNum( $resultsShown )
                );
            }
            $out .= Xml::tags( 'div', array( 'class' => 'results-info' ),
                Xml::tags( 'ul', null, Xml::tags( 'li', null, $top ) )
            );
        }

        $out .= Xml::element( 'div', array( 'style' => 'clear:both' ), '', false );
        $out .= Xml::closeElement('div');

        return $out;
    }

    protected function shortDialog( $term ) {
        $out = Html::hidden( 'title', $this->getTitle()->getPrefixedText() );
        $out .= Html::hidden( 'profile', $this->profile ) . "\n";
        // Term box
        $out .= Html::input( 'multiwikisearch', $term, 'multiwikisearch', array(
            'id' => $this->profile === 'advanced' ? 'powerSearchTextMultiWiki' : 'searchTextMultiWiki',
            'size' => '50',
            'autofocus'
        ) ) . "\n";
        $out .= Html::hidden( 'fulltext', 'Search' ) . "\n";
        $out .= Xml::submitButton( wfMsg( 'searchbutton' ) ) . "\n";
        return $out . $this->didYouMeanHtml;
    }

    /**
     * Make a search link with some target namespaces
     *
     * @param $term String
     * @param $namespaces Array ignored
     * @param $label String: link's text
     * @param $tooltip String: link's tooltip
     * @param $params Array: query string parameters
     * @return String: HTML fragment
     */
    protected function makeSearchLink( $term, $namespaces, $label, $tooltip, $params = array() ) {
        $opt = $params;
        foreach( $namespaces as $n ) {
            $opt['ns' . $n] = 1;
        }
        $opt['redirs'] = $this->searchRedirects;

        $stParams = array_merge(
            array(
                'multiwikisearch' => $term,
                'fulltext' => wfMsg( 'search' ),
                'wikilist' => $this->wikilist,
            ),
            $opt
        );

        return Xml::element(
            'a',
            array(
                'href' => $this->getTitle()->getLocalURL( $stParams ),
                'title' => $tooltip),
            $label
        );
    }

    /**
     * Check if query starts with image: prefix
     *
     * @param $term String: the string to check
     * @return Boolean
     */
    protected function startsWithImage( $term ) {
        global $wgContLang;

        $p = explode( ':', $term );
        if( count( $p ) > 1 ) {
            return $wgContLang->getNsIndex( $p[0] ) == NS_FILE;
        }
        return false;
    }

    /**
     * Check if query starts with all: prefix
     *
     * @param $term String: the string to check
     * @return Boolean
     */
    protected function startsWithAll( $term ) {

        $allkeyword = wfMsgForContent('searchall');

        $p = explode( ':', $term );
        if( count( $p ) > 1 ) {
            return $p[0]  == $allkeyword;
        }
        return false;
    }

    /**
     * @since 1.18
     */
    public function getSearchEngine() {
        if ( $this->searchEngine === null ) {
            $this->searchEngine = SearchEngine::create();
        }
        return $this->searchEngine;
    }

    /**
     * Users of hook SpecialSearchSetupEngine can use this to
     * add more params to links to not lose selection when
     * user navigates search results.
     * @since 1.18
     */
    public function setExtraParam( $key, $value ) {
        $this->extraParams[$key] = $value;
    }

    /**
     * Check in request uri existing wiki-key and return bool result
     *
     * @param $key
     * @return bool
     */
    protected function checkWiki($key)
    {
        if (empty($this->wikilist) || (!empty($this->wikilist) && in_array($key, $this->wikilist))) {
            return true;
        }
        return false;
    }

    /**
     * Add html block with selected wiki-name
     *
     * @param $label
     * @param $value
     * @return string
     */
    protected function addWikiCheckbox($label, $value)
    {
        $checked = [];
        if ($this->checkWiki($label)) {
            $checked = ['checked' => 'checked'];
        }

        return Xml::tags(
            'div',
            array( 'id' => '' ),
            Xml::label( $label, $value ) .
                Xml::element(
                    'input',
                    array(
                        'type'      =>'checkbox',
                        'value'     => $label,
                        'id'        => $value,
                        'name'      => 'wikilist[]'
                    ) + $checked
                )
        );
    }

    /**
     * Return html block existing in search wiki list
     *
     * @return string
     */

    public function showWikiList() {
        global $wgMiltiWikiSearchOption, $wgSitename ;

        if (!isset($wgMiltiWikiSearchOption['wiki']))
            return;

        $list_wiki = $this->addWikiCheckbox($wgSitename, 'mws_current');

        foreach ($wgMiltiWikiSearchOption['wiki'] as $key => $item) {
            $list_wiki .= $this->addWikiCheckbox($key, 'mws_'.$key);
        }

        $out = Xml::openElement('div', array( 'class' =>  'mws-list-container' ) );
        $out .= Xml::label( wfMsg( 'multiwikisearch-list' ), 'mws-search-togglelabel', array('class' => 'mws-list-label' ) ) .
            Xml::tags(
                'div',
                array( 'class' => 'mws-list' ),
                $list_wiki .
                    Xml::tags(
                        'div',
                        array( 'id' => '' ),
                        Xml::label( wfMsg( 'powersearch-toggleall' ), 'mws-toggleall', array('class' => 'mws-toggleall-label') ) .
                            Xml::element(
                                'input',
                                array(
                                    'type'=>'checkbox',
                                    'id' => 'mws-toggleall',
                                    'checked' => 'checked',
                                )
                            )
                    )
            );

        $out .= Xml::closeElement('div');
        return $out;
    }
}
