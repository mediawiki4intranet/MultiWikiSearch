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
    'description' => 'Allows to search in selected wiki, using MediaWiki API:Search'
);

$dir = dirname(__FILE__);
$wgAutoloadClasses['MultiWikiSearch'] = $dir."/MultiWikiSearch.class.php";
$wgExtensionMessagesFiles['MultiWikiSearch'] = $dir."/MultiWikiSearch.i18n.php";

$wgSpecialPages['MultiWikiSearch'] = 'MultiWikiSearch';
$wgSpecialPageGroups['MultiWikiSearch'] = 'redirects';

$commonModuleInfo = array(
    'localBasePath' => dirname( __FILE__ ),
    'remoteExtPath' => 'MultiWikiSearch',
);

$wgResourceModules['MultiWikiSearch.style'] = array(
    'styles'        => array('MultiWikiSearch.css')
) + $commonModuleInfo;

$wgResourceModules['MultiWikiSearch.script'] = array(
    'scripts'       => array('MultiWikiSearch.js'),
) + $commonModuleInfo;

