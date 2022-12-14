<?php
/**
 * @author Guillermo Vargas, http://joomla.vargas.co.cr
 * @email guille@vargas.co.cr
 * @version $Id$
 * @package XMap
 * @license GNU/GPL
 * @description XMap plugin for Kunena Forum.
 *
 * Modified by Masterpro project (https://masterpro.ws).
 */

defined( '_JEXEC' ) or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\Utilities\ArrayHelper;
use Kunena\Forum\Libraries\Factory\KunenaFactory;
use Kunena\Forum\Libraries\Forum\Category\KunenaCategoryHelper;
use Kunena\Forum\Libraries\Forum\Topic\KunenaTopicHelper;

/** Handles Kunena forum structure */
class xmap_com_kunena
{
    /*
     * This function is called before a menu item is printed. We use it to set the
     * proper uniqueid for the item.
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
        $params['groups'] = implode(',', $user->getAuthorisedViewLevels());
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
        }

        $params['table_prefix'] = xmap_com_kunena::getTablePrefix();
        xmap_com_kunena::getCategoryTree($xmap, $parent, $params, $catid);
    }

     // Builds the Kunena's tree

    function getCategoryTree($xmap, $parent, &$params, $parentCat)
    {
        $db = Factory::getDBO();

        // Load categories
            $catlink = 'index.php?option=com_kunena&amp;view=category&amp;catid=%s&Itemid='.$parent->id;
            $toplink = 'index.php?option=com_kunena&amp;view=topic&amp;catid=%s&amp;id=%s&Itemid='.$parent->id;

            $categories = KunenaCategoryHelper::getChildren($parentCat);

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
                $topics = KunenaTopicHelper::getLatestTopics($parentCat, 0, ($params['limit'] ? (int)$params['limit'] : PHP_INT_MAX), array('starttime', $params['days']));

            foreach($topics[1] as $topic)
            {
                $node = new stdclass;
                $node->id = $parent->id;
                $node->browserNav = $parent->browserNav;
                $node->uid = 'com_kunenat'.$topic->id;
                $node->name = $topic->subject;
                $node->priority = $params['topic_priority'];
                $node->changefreq = $params['topic_changefreq'];
                $node->modified = intval($topic->last_post_time);
                $node->link = sprintf($toplink, $topic->category_id, $topic->id);
                $node->expandible = false;
                $node->secure = $parent->secure;

                if($xmap->printNode($node) !== FALSE)
                {
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

    function getTablePrefix()
    {
        return '#__kunena';
    }

}
