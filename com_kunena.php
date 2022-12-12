<?php
/**
 * @author Guillermo Vargas, http://joomla.vargas.co.cr
 * @email guille@vargas.co.cr
 * @version $Id$
 * @package OsMap
 * @license GNU/GPL
 * @description OsMap plugin for Kunena Forum Component.
 *
 * Modified by Kubik-Rubik (http://joomla-extensions.kubik-rubik.de) to work with Kunena >= 2.0.1.
 * Modified by Masterpro project (https://masterpro.ws) for OsMap & PHP 8.*.
 */

use Joomla\CMS\Factory;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\Utilities\ArrayHelper;
use Kunena\Forum\Libraries\Factory\KunenaFactory;
use Kunena\Forum\Libraries\Forum\KunenaForum;
use Kunena\Forum\Libraries\Forum\Category\KunenaCategoryHelper;
use Kunena\Forum\Libraries\Forum\Topic\KunenaTopicHelper;
use Joomla\CMS\Version;

/** Handles Kunena forum structure */
class xmap_com_kunena
{
    /*
     * This function is called before a menu item is printed. We use it to set the
     * proper uniqueid for the item
     */
    static $profile;
    static $config;

    function prepareMenuItem($node, &$params)
    {
        $link_query = parse_url($node->link);
        parse_str(html_entity_decode($link_query['query']), $link_vars);
        $catid = intval(ArrayHelper::getValue($link_vars, 'catid', 0));
        $id = intval(ArrayHelper::getValue($link_vars, 'id', 0));
        $func = ArrayHelper::getValue($link_vars, 'func', '', '');
        if($func = 'showcat' && $catid)
        {
            $node->uid = 'com_kunenac'.$catid;
            $node->expandible = false;
        }
        elseif($func = 'view' && $id)
        {
            $node->uid = 'com_kunenaf'.$id;
            $node->expandible = false;
        }
    }

    function getTree($xmap, $parent, &$params)
    {
        if($xmap->isNews) // This component does not provide news content. don't waste time/resources
            return false;

        // Make sure that we can load the kunena api
        if(!xmap_com_kunena::loadKunenaApi())
        {
            return false;
        }

        if(!self::$profile)
        {
            self::$config = KunenaFactory::getConfig();
            ;
            self::$profile = KunenaFactory::getUser();
        }

        $user = Factory::getUser();
        $catid = 0;

        $link_query = parse_url($parent->link);
        if(!isset($link_query['query']))
        {
            return;
        }

        parse_str(html_entity_decode($link_query['query']), $link_vars);


        // Kubik-Rubik Solution - get the correct view in Kunena >= 2.0.1 - START
        $view = ArrayHelper::getValue($link_vars, 'view', '');
        $layout = ArrayHelper::getValue($link_vars, 'layout', '');
        $catid_link = ArrayHelper::getValue($link_vars, 'catid', 0);

        if($view == 'category' AND (!$layout OR 'list' == $layout))
        {
            if(!empty($catid_link))
            {
                $link_query = parse_url($parent->link);
                parse_str(html_entity_decode($link_query['query']), $link_vars);
                $catid = ArrayHelper::getValue($link_vars, 'catid', 0);
            }
            else
            {
                $catid = 0;
            }

            // Get ItemID of the main menu entry of the component
            $component = ComponentHelper::getComponent('com_kunena');

            // Deprecated $menus = JApplication::getMenu('site', array());
            $menus = Factory::getApplication()->getMenu('site', array());
            $items = $menus->getItems('component_id', $component->id);

            foreach($items as $item)
            {
                if(@$item->query['view'] == 'home')
                {
                    $parent->id = $item->id;
                    break;
                }
            }
        }
        else
        {
            return true;
        }
        // Kubik-Rubik Solution - END

        $include_topics = ArrayHelper::getValue($params, 'include_topics', 1);
        $include_topics = ( $include_topics == 1
                || ( $include_topics == 2 && $xmap->view == 'xml')
                || ( $include_topics == 3 && $xmap->view == 'html')
                || $xmap->view == 'navigator');
        $params['include_topics'] = $include_topics;

        $priority = ArrayHelper::getValue($params, 'cat_priority', $parent->priority);
        $changefreq = ArrayHelper::getValue($params, 'cat_changefreq', $parent->changefreq);
        if($priority == '-1')
            $priority = $parent->priority;
        if($changefreq == '-1')
            $changefreq = $parent->changefreq;

        $params['cat_priority'] = $priority;
        $params['cat_changefreq'] = $changefreq;
        $jVersion = new Version();
        if ( $jVersion->isCompatible('3.1') ){
            $params['groups'] = implode(',', $user->getAuthorisedViewLevels());
        }
        else{// legacy
            $params['groups'] = implode(',', $user->authorisedLevels());
        }

        $priority = ArrayHelper::getValue($params, 'topic_priority', $parent->priority);
        $changefreq = ArrayHelper::getValue($params, 'topic_changefreq', $parent->changefreq);
        if($priority == '-1')
            $priority = $parent->priority;

        if($changefreq == '-1')
            $changefreq = $parent->changefreq;

        $params['topic_priority'] = $priority;
        $params['topic_changefreq'] = $changefreq;

        if($include_topics)
        {
            $ordering = ArrayHelper::getValue($params, 'topics_order', 'ordering');
            if(!in_array($ordering, array('id', 'ordering', 'time', 'subject', 'hits')))
                $ordering = 'ordering';
            $params['topics_order'] = 't.`'.$ordering.'`';
            $params['include_pagination'] = ($xmap->view == 'xml');

            $params['limit'] = '';
            $params['days'] = '';

            // Kubik-Rubik Solution - limit must be only the number + check whether variable is numeric - START
            $limit = ArrayHelper::getValue($params, 'max_topics', '');

            if(is_numeric($limit))
            {
                $params['limit'] = $limit;
            }

            $days = ArrayHelper::getValue($params, 'max_age', '');
            $params['days'] = false;

            if(is_numeric($days))
            {
                $params['days'] = ($xmap->now - (intval($days) * 86400));
            }
            // Kubik-Rubik Solution - END
        }

        $params['table_prefix'] = xmap_com_kunena::getTablePrefix();

        xmap_com_kunena::getCategoryTree($xmap, $parent, $params, $catid);
    }

    /*
     * Builds the Kunena's tree
     */

    function getCategoryTree($xmap, $parent, &$params, $parentCat)
    {
        $db = Factory::getDBO();

        // Load categories
        if(self::getKunenaMajorVersion() >= '2.0')
        {
            // Kunena 2.0+
            $catlink = 'index.php?option=com_kunena&amp;view=category&amp;catid=%s&Itemid='.$parent->id;
            $toplink = 'index.php?option=com_kunena&amp;view=topic&amp;catid=%s&amp;id=%s&Itemid='.$parent->id;

            $categories = KunenaCategoryHelper::getChildren($parentCat);
        }
        else
        {
            $catlink = 'index.php?option=com_kunena&amp;func=showcat&amp;catid=%s&Itemid='.$parent->id;
            $toplink = 'index.php?option=com_kunena&amp;func=view&amp;catid=%s&amp;id=%s&Itemid='.$parent->id;

            if(self::getKunenaMajorVersion() >= '1.6')
            {
                // Kunena 1.6+
                kimport('session');
                $session = KunenaFactory::getSession();
                $session->updateAllowedForums();
                $allowed = $session->allowed;
                $query = "SELECT id, name FROM `#__kunena_categories` WHERE parent={$parentCat} AND id IN ({$allowed}) ORDER BY ordering";
            }
            else
            {
                // Kunena 1.0+
                $query = "SELECT id, name FROM `{$params['table_prefix']}_categories` WHERE parent={$parentCat} AND published=1 AND pub_access=0 ORDER BY ordering";
            }
            $db->setQuery($query);
            $categories = $db->loadObjectList();
        }

        /* get list of categories */
        $xmap->changeLevel(1);
        foreach($categories as $cat)
        {
            $node = new stdclass;
            $node->id = $parent->id;
            $node->browserNav = $parent->browserNav;
            $node->uid = 'com_kunenac'.$cat->id;
            $node->name = $cat->name;
            $node->priority = $params['cat_priority'];
            $node->changefreq = $params['cat_changefreq'];
            $node->link = sprintf($catlink, $cat->id);
            $node->expandible = true;
            $node->secure = $parent->secure;
            if($xmap->printNode($node) !== FALSE)
            {
                xmap_com_kunena::getCategoryTree($xmap, $parent, $params, $cat->id);
            }
        }

        if($params['include_topics'])
        {
            if(self::getKunenaMajorVersion() >= '2.0')
            {
                // Kunena 2.0+
                // TODO: orderby parameter is missing:
                $topics = KunenaTopicHelper::getLatestTopics($parentCat, 0, ($params['limit'] ? (int)$params['limit'] : PHP_INT_MAX), array('starttime', $params['days']));
            }
            else
            {
                $access = KunenaFactory::getAccessControl();
                $hold = $access->getAllowedHold(self::$profile, $parentCat);
                // Kunena 1.0+
                $query = "SELECT t.id, t.catid, t.subject, max(m.time) as time, count(m.id) as msgcount
                    FROM {$params['table_prefix']}_messages t
                    INNER JOIN {$params['table_prefix']}_messages AS m ON t.id = m.thread
                    WHERE t.catid=$parentCat AND t.parent=0
                        AND t.hold in ({$hold})
                    GROUP BY m.`thread`
                    ORDER BY {$params['topics_order']} DESC";
                if($params['days'])
                {
                    $query = "SELECT * FROM ($query) as topics WHERE time >= {$params['days']}";
                }
                #echo str_replace('#__','mgbj2_',$query);
                $db->setQuery($query, 0, $params['limit']);
                $topics = $db->loadObjectList();
            }

            // Kubik-Rubik Solution - call the array item 1, because 0 only contains the number of topics in this category - START
            foreach($topics[1] as $topic)
            // Kubik-Rubik Solution - END
            {
                $node = new stdclass;
                $node->id = $parent->id;
                $node->browserNav = $parent->browserNav;
                $node->uid = 'com_kunenat'.$topic->id;
                $node->name = $topic->subject;
                $node->priority = $params['topic_priority'];
                $node->changefreq = $params['topic_changefreq'];

                // Kubik-Rubik Solution - names have been changed - START
                $node->modified = intval($topic->last_post_time);
                $node->link = sprintf($toplink, $topic->category_id, $topic->id);
                // Kubik-Rubik Solution - END

                $node->expandible = false;
                $node->secure = $parent->secure;
                if($xmap->printNode($node) !== FALSE)
                {
                    // Pagination will not work with K2.0, revisit this when that version is out and stable
                    if($params['include_pagination'] && isset($topic->msgcount) && $topic->msgcount > self::$config->messages_per_page)
                    {
                        $msgPerPage = self::$config->messages_per_page;
                        $threadPages = ceil($topic->msgcount / $msgPerPage);
                        for($i = 2; $i <= $threadPages; $i++)
                        {
                            $subnode = new stdclass;
                            $subnode->id = $node->id;
                            $subnode->uid = $node->uid.'p'.$i;
                            $subnode->name = "[$i]";
                            $subnode->seq = $i;
                            $subnode->link = $node->link.'&limit='.$msgPerPage.'&limitstart='.(($i - 1) * $msgPerPage);
                            $subnode->browserNav = $node->browserNav;
                            $subnode->priority = $node->priority;
                            $subnode->changefreq = $node->changefreq;
                            $subnode->modified = $node->modified;
                            $subnode->secure = $node->secure;
                            $xmap->printNode($subnode);
                        }
                    }
                }
            }
        }
        $xmap->changeLevel(-1);
    }

    private static function loadKunenaApi()
    {
        if(!defined('KUNENA_LOADED'))
        {
            jimport('joomla.application.component.helper');
            // Check if Kunena component is installed/enabled
            if(!ComponentHelper::isEnabled('com_kunena', true))
            {
                return false;
            }

            // Check if Kunena API exists
            $kunena_api = JPATH_ADMINISTRATOR.'/components/com_kunena/api.php';
            if(!is_file($kunena_api))
                return false;

            // Load Kunena API
            require_once ($kunena_api);
        }
        return true;
    }

    /**
     * Based on Matias' version (Thanks)
     * See: http://docs.kunena.org/index.php/Developing_Kunena_Router
     */
    function getKunenaMajorVersion()
    {
        static $version;
        if(!$version)
        {
            if(class_exists('KunenaForum'))
            {
                $version = KunenaForum::versionMajor();
            }
            elseif(class_exists('Kunena'))
            {
                $version = substr(Kunena::version(), 0, 3);
            }
            elseif(is_file(JPATH_ROOT.'/components/com_kunena/lib/kunena.defines.php'))
            {
                $version = '1.5';
            }
            elseif(is_file(JPATH_ROOT.'/components/com_kunena/lib/kunena.version.php'))
            {
                $version = '1.0';
            }
        }
        return $version;
    }

    function getTablePrefix()
    {
        $version = self::getKunenaMajorVersion();
        if($version <= 1.5)
        {
            return '#__fb';
        }
        return '#__kunena';
    }

}
