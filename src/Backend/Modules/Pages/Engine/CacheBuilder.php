<?php

namespace Backend\Modules\Pages\Engine;

/*
 * This file is part of Fork CMS.
 *
 * For the full copyright and license information, please view the license
 * file that was distributed with this source code.
 */

use Symfony\Component\Filesystem\Filesystem;

/**
 * In this file, the pages cache is build
 *
 * @author Wouter Sioen <wouter@wijs.be>
 */
class CacheBuilder
{
    /**
     * @var \SpoonDatabase
     */
    protected $database;

    protected $blocks;
    protected $siteMapId;

    /**
     * @param \SpoonDatabase $database
     */
    public function __construct(\SpoonDatabase $database)
    {
        $this->database = $database;
    }

    /**
     * Builds the pages cache
     *
     * @param string $language The language to build the cache for.
     */
    public function buildCache($language)
    {
        list($keys, $navigation) = $this->getData($language);

        $fs = new Filesystem();
        $fs->dumpFile(
            FRONTEND_CACHE_PATH . '/Navigation/keys_' . $language . '.php',
           $this->dumpKeys($keys, $language)
        );
        $fs->dumpFile(
            FRONTEND_CACHE_PATH . '/Navigation/navigation_' . $language . '.php',
            $this->dumpNavigation($navigation, $language)
        );
        $fs->dumpFile(
            FRONTEND_CACHE_PATH . '/Navigation/editor_link_list_' . $language . '.js',
            $this->dumpEditorLinkList($navigation, $keys, $language)
        );
    }

    /**
     * Fetches all data from the database
     *
     * @param $language
     * @return array tupple containing keys and navigation
     */
    protected function getData($language)
    {
        // get tree
        $levels = Model::getTree(array(0), null, 1, $language);

        $keys = array();
        $navigation = array();

        // loop levels
        foreach ($levels as $pages) {
            // loop all items on this level
            foreach ($pages as $pageId => $page) {
                $temp = $this->getPageData($keys, $page, $language);

                // add it
                $navigation[$page['type']][$page['parent_id']][$pageId] = $temp;
            }
        }

        // order by URL
        asort($keys);

        return array($keys, $navigation);
    }

    /**
     * Fetches the pagedata for a certain page array
     * It also adds the page data to the keys array
     *
     * @param  array  &$keys
     * @param  array  $page
     * @param  string $language
     * @return array  An array containing more data for the page
     */
    protected function getPageData(&$keys, $page, $language)
    {
        $parentID = (int) $page['parent_id'];

        // init URLs
        $languageURL = (SITE_MULTILANGUAGE) ? '/' . $language . '/' : '/';
        $URL = (isset($keys[$parentID])) ? $keys[$parentID] : '';

        // home is special
        if ($page['id'] == 1) {
            $page['url'] = '';
            if (SITE_MULTILANGUAGE) {
                $languageURL = rtrim($languageURL, '/');
            }
        }

        // add it
        $keys[$page['id']] = trim($URL . '/' . $page['url'], '/');

        // unserialize
        if (isset($page['meta_data'])) {
            $page['meta_data'] = @unserialize($page['meta_data']);
        }

        // build navigation array
        $pageData = array(
            'page_id' => (int) $page['id'],
            'url' => $page['url'],
            'full_url' => $languageURL . $keys[$page['id']],
            'title' => addslashes($page['title']),
            'navigation_title' => addslashes($page['navigation_title']),
            'has_extra' => (bool) ($page['has_extra'] == 'Y'),
            'no_follow' => (bool) (isset($page['meta_data']['seo_follow']) && $page['meta_data']['seo_follow'] == 'nofollow'),
            'hidden' => (bool) ($page['hidden'] == 'Y'),
            'extra_blocks' => null,
            'has_children' => (bool) ($page['has_children'] == 'Y')
        );

        $pageData['extra_blocks'] = $this->getPageExtraBlocks($page, $pageData);
        $pageData['tree_type'] = $this->getPageTreeType($page, $pageData);

        return $pageData;
    }

    protected function getPageTreeType($page, $pageData)
    {
        // calculate tree-type
        $treeType = 'page';
        if ($page['hidden'] == 'Y') {
            $treeType = 'hidden';
        }

        // homepage should have a special icon
        if ($page['id'] == 1) {
            $treeType = 'home';
        } elseif ($page['id'] == 404) {
            $treeType = 'error';
        } elseif ($page['id'] < 404 && substr_count($page['extra_ids'], $this->getSitemapId()) > 0) {
            // get extras
            $extraIDs = explode(',', $page['extra_ids']);

            // loop extras
            foreach ($extraIDs as $id) {
                // check if this is the sitemap id
                if ($id == $this->getSitemapId()) {
                    // set type
                    $treeType = 'sitemap';

                    // break it
                    break;
                }
            }
        }

        // any data?
        if (isset($page['data'])) {
            // get data
            $data = unserialize($page['data']);

            // internal alias?
            if (isset($data['internal_redirect']['page_id']) && $data['internal_redirect']['page_id'] != '') {
                $pageData['redirect_page_id'] = $data['internal_redirect']['page_id'];
                $pageData['redirect_code'] = $data['internal_redirect']['code'];
                $treeType = 'redirect';
            }

            // external alias?
            if (isset($data['external_redirect']['url']) && $data['external_redirect']['url'] != '') {
                $pageData['redirect_url'] = $data['external_redirect']['url'];
                $pageData['redirect_code'] = $data['external_redirect']['code'];
                $treeType = 'redirect';
            }

            // direct action?
            if (isset($data['is_action']) && $data['is_action']) {
                $treeType = 'direct_action';
            }
        }

        return $treeType;
    }

    protected function getPageExtraBlocks($page, $pageData)
    {
        // add extras to the page array
        if ($page['extra_ids'] !== null) {
            $blocks = $this->getBlocks();
            $ids = (array) explode(',', $page['extra_ids']);
            $pageBlocks = array();

            foreach ($ids as $id) {
                $id = (int) $id;

                // available in extras, so add it to the pageData-array
                if (isset($blocks[$id])) {
                    $pageBlocks[$id] = $blocks[$id];
                }
            }

            return $pageBlocks;
        }
    }

    /**
     * Returns an array containing all extras
     *
     * @return array
     */
    protected function getBlocks()
    {
        if (empty($this->blocks)) {
            $this->blocks = (array) $this->database->getRecords(
                'SELECT i.id, i.module, i.action
                 FROM modules_extras AS i
                 WHERE i.type = ? AND i.hidden = ?',
                array('block', 'N'),
                'id'
            );
        }

        return $this->blocks;
    }

    /**
     * Returns an array containing all widgets
     *
     * @return array
     */
    protected function getSitemapId()
    {
        if (empty($this->sitemapId)) {
            $widgets = (array) $this->database->getRecords(
                'SELECT i.id, i.module, i.action
                 FROM modules_extras AS i
                 WHERE i.type = ? AND i.hidden = ?',
                array('widget', 'N'),
                'id'
            );

            // search sitemap
            foreach ($widgets as $id => $row) {
                if ($row['action'] == 'Sitemap') {
                    $this->sitemapId = $id;
                    break;
                }
            }
        }

        return $this->sitemapId;
    }

    /**
     * Get the order
     *
     * @param  array  $navigation The navigation array.
     * @param  string $type       The type of navigation.
     * @param  int    $parentId   The Id to start from.
     * @param  array  $order      The array to hold the order.
     * @return array
     */
    protected function getOrder($navigation, $type = 'page', $parentId = 0, $order = array())
    {
        // loop alle items for the type and parent
        foreach ($navigation[$type][$parentId] as $id => $page) {
            // add to array
            $order[$id] = $page['full_url'];

            // children of root/footer/meta-pages are stored under the page type
            if (($type == 'root' || $type == 'footer' || $type == 'meta') && isset($navigation['page'][$id])) {
                // process subpages
                $order = $this->getOrder($navigation, 'page', $id, $order);
            } elseif (isset($navigation[$type][$id])) {
                // process subpages
                $order = $this->getOrder($navigation, $type, $id, $order);
            }
        }

        // return
        return $order;
    }

    /**
     * Saves the keys file
     *
     * @param  array  $keys     The page keys
     * @param  string $language The language to save the file for
     * @return string           The full content for the cache file
     */
    protected function dumpKeys($keys, $language)
    {
        // write the key-file
        $keysString = '<?php' . "\n\n";
        $keysString .= $this->getCacheHeader(
            'the mapping between a pageID and the URL'
        );
        $keysString .= '$keys = array();' . "\n\n";

        // loop all keys
        foreach ($keys as $pageId => $URL) {
            $keysString .= '$keys[' . $pageId . '] = \'' . $URL . '\';' . "\n";
        }

        // end file
        $keysString .= "\n" . '?>';

        return $keysString;
    }

    /**
     * Saves the keys file
     *
     * @param  array  $navigation The full navigation array
     * @param  string $language   The language to save the file for
     * @return string           The full content for the cache file
     */
    protected function dumpNavigation($navigation, $language)
    {
        // write the navigation-file
        $navigationString = '<?php' . "\n\n";
        $navigationString .= $this->getCacheHeader(
            'more information about the page-structure'
        );
        $navigationString .= '$navigation = array();' . "\n\n";

        // loop all types
        foreach ($navigation as $type => $pages) {
            // loop all parents
            foreach ($pages as $parentID => $page) {
                // loop all pages
                foreach ($page as $pageId => $properties) {
                    // loop properties
                    foreach ($properties as $key => $value) {
                        // page_id should be an integer
                        if (is_int($value)) {
                            $line = '$navigation[\'' . $type . '\'][' . $parentID . '][' . $pageId .
                                    '][\'' . $key . '\'] = ' . $value . ';' . "\n";
                        } elseif (is_bool($value)) {
                            if ($value) {
                                $line = '$navigation[\'' . $type . '\'][' . $parentID . '][' . $pageId .
                                        '][\'' . $key . '\'] = true;' . "\n";
                            } else {
                                $line = '$navigation[\'' . $type . '\'][' . $parentID . '][' . $pageId .
                                        '][\'' . $key . '\'] = false;' . "\n";
                            }
                        } elseif ($key == 'extra_blocks') {
                            if ($value === null) {
                                $line = '$navigation[\'' . $type . '\'][' . $parentID . '][' . $pageId .
                                        '][\'' . $key . '\'] = null;' . "\n";
                            } else {
                                // init var
                                $blocks = array();

                                foreach ($value as $row) {
                                    // init var
                                    $temp = 'array(';

                                    // add properties
                                    $temp .= '\'id\' => ' . (int) $row['id'];
                                    $temp .= ', \'module\' => \'' . (string) $row['module'] . '\'';

                                    if ($row['action'] === null) {
                                        $temp .= ', \'action\' => null';
                                    } else {
                                        $temp .= ', \'action\' => \'' . (string) $row['action'] . '\'';
                                    }

                                    $temp .= ')';

                                    // add into extras
                                    $blocks[] = $temp;
                                }

                                // set line
                                $line = '$navigation[\'' . $type . '\'][' . $parentID . '][' .
                                        $pageId . '][\'' . $key . '\'] = array(' .
                                        implode(', ', $blocks) . ');' . "\n";
                            }
                        } else {
                            $line = '$navigation[\'' . $type . '\'][' . $parentID . '][' .
                                    $pageId . '][\'' . $key . '\'] = \'' . (string) $value . '\';' . "\n";
                        }

                        // add line
                        $navigationString .= $line;
                    }

                    // end
                    $navigationString .= "\n";
                }
            }
        }

        // end file
        $navigationString .= '?>';

        return $navigationString;
    }

    /**
     * Save the link list
     *
     * @param  array  $navigation The full navigation array
     * @param  array  $keys       The page keys
     * @param  string $language   The language to save the file for
     * @return string             The full content for the cache file
     */
    protected function dumpEditorLinkList($navigation, $keys, $language)
    {
        // get the order
        foreach (array_keys($navigation) as $type) {
            $order[$type] = $this->getOrder($navigation, $type, 0);
        }

        // start building the cache file
        $editorLinkListString = $this->getCacheHeader(
            'the links that can be used by the editor'
        );

        // init var
        $links = array();

        // init var
        $cachedTitles = (array) $this->database->getPairs(
            'SELECT i.id, i.navigation_title
             FROM pages AS i
             WHERE i.id IN(' . implode(',', array_keys($keys)) . ')
             AND i.language = ? AND i.status = ?',
            array($language, 'active')
        );

        // loop the types in the order we want them to appear
        foreach (array('page', 'meta', 'footer', 'root') as $type) {
            // any pages?
            if (isset($order[$type])) {
                // loop pages
                foreach ($order[$type] as $pageId => $url) {
                    // skip if we don't have a title
                    if (!isset($cachedTitles[$pageId])) {
                        continue;
                    }

                    // get the title
                    $title = \SpoonFilter::htmlspecialcharsDecode($cachedTitles[$pageId]);

                    // split into chunks
                    $urlChunks = explode('/', $url);

                    // remove the language chunk
                    $urlChunks = (SITE_MULTILANGUAGE) ? array_slice($urlChunks, 2) : array_slice($urlChunks, 1);

                    // subpage?
                    if (count($urlChunks) > 1) {
                        // loop while we have more then 1 chunk
                        while (count($urlChunks) > 1) {
                            // remove last chunk of the url
                            array_pop($urlChunks);

                            // build the temporary URL, so we can search for an id
                            $tempUrl = implode('/', $urlChunks);

                            // search the pageID
                            $tempPageId = array_search($tempUrl, $keys);

                            // prepend the title
                            if (!isset($cachedTitles[$tempPageId])) {
                                $title = ' > ' . $title;
                            } else {
                                $title = $cachedTitles[$tempPageId] . ' > ' . $title;
                            }
                        }
                    }

                    // add
                    $links[] = array($title, $url);
                }
            }
        }

        // add JSON-string
        $editorLinkListString .= 'var linkList = ' . json_encode($links) . ';';

        return $editorLinkListString;
    }

    /**
     * Gets the header for cache files
     *
     * @param  string $itContainsMessage A message about the content of the file
     * @return string A comment to be used in the cache file
     */
    protected function getCacheHeader($itContainsMessage)
    {
        $cacheHeader = '/**' . "\n";
        $cacheHeader .= ' * This file is generated by Fork CMS, it contains' . "\n";
        $cacheHeader .= ' * ' . $itContainsMessage . "\n";
        $cacheHeader .= ' * ' . "\n";
        $cacheHeader .= ' * Fork CMS' . "\n";
        $cacheHeader .= ' * @generated ' . date('Y-m-d H:i:s') . "\n";
        $cacheHeader .= ' */' . "\n\n";

        return $cacheHeader;
    }
}
