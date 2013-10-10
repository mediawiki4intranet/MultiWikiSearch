<?php

/* MultiWikiSearch extension
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

# This extension show search results from all wiki

if (!defined('MEDIAWIKI'))
    die("This requires the MediaWiki environment.");

$wgExtensionCredits['specialpage'][] = array(
    'version'     => '1.0',
    'name'        => 'MultiWikiSearch',
    'author'      => 'Andrey Krasilnikov',
    'email'       => 'z010107@gmail.com',
    'url'         => 'http://wiki.4intra.net/MultiWikiSearch',
    'description' => 'Allows to search in multiple wikis at once, using MediaWiki Search API'
);

$dir = dirname(__FILE__);
$wgAutoloadClasses['MultiWikiSearch'] = $dir."/MultiWikiSearch.class.php";
$wgExtensionMessagesFiles['MultiWikiSearch'] = $dir."/MultiWikiSearch.i18n.php";

$wgSpecialPages['MultiWikiSearch'] = 'MultiWikiSearch';
$wgSpecialPageGroups['MultiWikiSearch'] = 'redirects';

$wgResourceModules['ext.MultiWikiSearch'] = array(
    'localBasePath' => $dir,
    'remoteExtPath' => 'MultiWikiSearch',
    'styles'        => array('MultiWikiSearch.css'),
    'scripts'       => array('MultiWikiSearch.js'),
);

// Default configuration:

// Wikis to search in
$wgMultiWikiSearchWikis = array(
    // <site_name> => <script_path> (http://domain.com/w/)
);

// If you have several wikis with shared user databases, i.e. if $wgSharedDB is set
// and $wgSharedTables contains 'user' table, you can specify true or
// array(<site_name> => true) here so the extension will pass the autentication
// to other wikis during search.
$wgMultiWikiSearchSharedUsers = true;

$wgHooks['SpecialSearchSetupEngine'][] = 'linkToMultiWikiSearch';

function linkToMultiWikiSearch()
{
    global $wgOut;

    $wgOut->addHTML(
        '<style>#bodyContent { position: relative; } .link-multi-wiki-search { position: absolute; top: 3px; left: 400px; } </style>
        <a href="'.SpecialPage::getTitleFor( 'MultiWikiSearch' )->getLocalURL().'" class="link-multi-wiki-search">'.
            wfMsg('linkToMultiWikiSearchPage').
        '</a>'
    );
    return true;
}