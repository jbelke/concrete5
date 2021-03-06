<?php

namespace Concrete\Core\Page\Stack;

use Area;
use Concrete\Core\Multilingual\Page\Section\Section;
use GlobalArea;
use Config;
use Database;
use Core;
use Page;
use PageType;

/**
 * Class Stack.
 *
 * @package Concrete\Core\Page\Stack
 */
class Stack extends Page
{
    const ST_TYPE_USER_ADDED = 0;
    const ST_TYPE_GLOBAL_AREA = 20;

    /**
     * @param string $type
     *
     * @return int
     */
    public static function mapImportTextToType($type)
    {
        switch ($type) {
            case 'global_area':
                return static::ST_TYPE_GLOBAL_AREA;
                break;
            default:
                return static::ST_TYPE_USER_ADDED;
                break;
        }
    }

    /**
     * @param $stackName
     *
     * @return Stack
     */
    public static function getOrCreateGlobalArea($stackName)
    {
        $stack = static::getByName($stackName);
        if (!$stack) {
            $stack = static::addStack($stackName, static::ST_TYPE_GLOBAL_AREA);
        }

        return $stack;
    }

    /**
     * @param string $stackName
     * @param string $cvID
     *
     * @return Page
     */
    public static function getByName($stackName, $cvID = 'RECENT')
    {
        $c = Page::getCurrentPage();
        if (is_object($c) && (!$c->isError())) {
            $identifier = sprintf('/stack/name/%s/%s', $stackName, $c->getCollectionID());
            $cache = Core::make('cache/request');
            $item = $cache->getItem($identifier);
            if (!$item->isMiss()) {
                $cID = $item->get();
            } else {
                $item->lock();
                $db = Database::connection();
                $ms = false;
                $detector = Core::make('multilingual/detector');
                if ($detector->isEnabled()) {
                    $ms = Section::getBySectionOfSite($c);
                    if (!is_object($ms)) {
                        $ms = $detector->getPreferredSection();
                    }
                }

                if (is_object($ms)) {
                    $cID = $db->GetOne('select cID from Stacks where stName = ? and stMultilingualSection = ?', array($stackName, $ms->getCollectionID()));
                } else {
                    $cID = $db->GetOne('select cID from Stacks where stName = ?', array($stackName));
                }
                $item->set($cID);
            }
        } else {
            $cID = Database::connection()->GetOne('select cID from Stacks where stName = ?', array($stackName));
        }

        return $cID ? static::getByID($cID, $cvID) : false;
    }

    /**
     * @param int    $cID
     * @param string $cvID
     *
     * @return bool|Page
     */
    public static function getByID($cID, $cvID = 'RECENT')
    {
        $c = parent::getByID($cID, $cvID, 'Stack');

        if (static::isValidStack($c)) {
            return $c;
        }

        return false;
    }

    /**
     * @param Stack $stack
     *
     * @return bool
     */
    protected static function isValidStack($stack)
    {
        return $stack->getPageTypeHandle() == STACKS_PAGE_TYPE;
    }

    /**
     * @param string $stackName
     * @param int    $type
     *
     * @return Page
     */
    public static function addStack($stackName, $type = 0)
    {
        $data = array();

        $parent = Page::getByPath(STACKS_PAGE_PATH);
        $data = array();
        $data['name'] = $stackName;
        if (!$stackName) {
            $data['name'] = t('No Name');
        }
        $pagetype = PageType::getByHandle(STACKS_PAGE_TYPE);
        $page = $parent->add($pagetype, $data);

        // we have to do this because we need the area to exist before we try and add something to it.
        Area::getOrCreate($page, STACKS_AREA_NAME);

        // finally we add the row to the stacks table
        $db = Database::connection();
        $stackCID = $page->getCollectionID();
        $v = array($stackName, $stackCID, $type);
        $db->Execute('insert into Stacks (stName, cID, stType) values (?, ?, ?)', $v);

        $stack = static::getByID($stackCID);

        // If the multilingual add-on is enabled, we need to take this stack and copy it into all non-default stack categories.
        if (Core::make('multilingual/detector')->isEnabled()) {
            $list = Section::getList();
            foreach ($list as $section) {
                if (!$section->isDefaultMultilingualSection()) {
                    $category = StackCategory::getCategoryFromMultilingualSection($section);
                    if (!is_object($category)) {
                        $category = StackCategory::createFromMultilingualSection($section);
                    }
                    $stack->duplicate($category->getPage());
                }
            }
            StackList::rescanMultilingualStacks();
        }

        return $stack;
    }

    /**
     * @param |\Concrete\Core\Page\Collection $nc
     * @param bool $preserveUserID
     *
     * @return Stack
     */
    public function duplicate($nc = null, $preserveUserID = false)
    {
        if (!is_object($nc)) {
            // There is not necessarily need to provide the parent
            // page for the duplicate since for stacks, that is
            // always the same page.
            $nc = Page::getByPath(STACKS_PAGE_PATH);
        }
        $page = parent::duplicate($nc, $preserveUserID);

        // we have to do this because we need the area to exist before we try and add something to it.
        Area::getOrCreate($page, STACKS_AREA_NAME);

        $db = Database::connection();
        $v = array($page->getCollectionName(), $page->getCollectionID(), $this->getStackType());
        $db->Execute('insert into Stacks (stName, cID, stType) values (?, ?, ?)', $v);

        // Make sure we return an up-to-date record
        return static::getByID($page->getCollectionID());
    }

    /**
     * @return int
     */
    public function getStackType()
    {
        $db = Database::connection();

        return $db->GetOne('select stType from Stacks where cID = ?', array($this->getCollectionID()));
    }

    /**
     * @param $data
     *
     * @return bool
     */
    public function update($data)
    {
        if (isset($data['stackName'])) {
            $txt = Core::make('helper/text');
            $data['cName'] = $data['stackName'];
            $data['cHandle'] = str_replace('-', Config::get('concrete.seo.page_path_separator'), $txt->urlify($data['stackName']));
        }
        $worked = parent::update($data);

        if (isset($data['stackName'])) {
            // Make sure the stack path is always up-to-date after a name change
            $this->rescanCollectionPath();

            $db = Database::connection();
            $stackName = $data['stackName'];
            $db->Execute('update Stacks set stName = ? WHERE cID = ?', array($stackName, $this->getCollectionID()));
        }

        return $worked;
    }

    /**
     * @return bool
     */
    public function delete()
    {
        if ($this->getStackType() == static::ST_TYPE_GLOBAL_AREA) {
            GlobalArea::deleteByName($this->getStackName());
        }

        parent::delete();
        $db = Database::connection();

        return $db->Execute('delete from Stacks where cID = ?', array($this->getCollectionID()));
    }

    /**
     * @return string
     */
    public function getStackName()
    {
        $db = Database::connection();

        return $db->GetOne('select stName from Stacks where cID = ?', array($this->getCollectionID()));
    }

    /**
     * @return bool
     */
    public function display()
    {
        $ax = Area::get($this, STACKS_AREA_NAME);
        $ax->disableControls();
        $ax->display($this);

        return true;
    }

    /**
     * @param Page $pageNode
     */
    public function export($pageNode)
    {
        $p = $pageNode->addChild('stack');
        $p->addAttribute('name', Core::make('helper/text')->entities($this->getCollectionName()));
        if ($this->getStackTypeExportText()) {
            $p->addAttribute('type', $this->getStackTypeExportText());
        }

        $db = Database::connection();
        // you shouldn't ever have a sub area in a stack but just in case.
        $r = $db->Execute('select arHandle from Areas where cID = ? and arParentID = 0', array($this->getCollectionID()));
        while ($row = $r->FetchRow()) {
            $ax = Area::get($this, $row['arHandle']);
            $ax->export($p, $this);
        }
    }

    /**
     * @return bool|string
     */
    public function getStackTypeExportText()
    {
        switch ($this->getStackType()) {
            case static::ST_TYPE_GLOBAL_AREA:
                return 'global_area';
                break;
            default:
                return false;
                break;
        }
    }

    public function getMultilingualSection()
    {
        $db = Database::connection();
        $cID = $db->GetOne('select stMultilingualSection from Stacks where cID = ?', array($this->getCollectionID()));
        if ($cID) {
            return Section::getByID($cID);
        }
    }

    public function updateMultilingualSection(Section $section)
    {
        $db = Database::connection();
        $db->Execute('update Stacks set stMultilingualSection = ? where cID = ?', array($section->getCollectionID(), $this->getCollectionID()));
    }
}
