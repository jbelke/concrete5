<?
namespace Concrete\Block\CoreAreaLayout;

use Concrete\Core\Area\SubArea;
use Loader;
use \Concrete\Core\Block\BlockController;
use \Concrete\Core\Area\Layout\Layout as AreaLayout;
use \Concrete\Core\Area\Layout\Preset as AreaLayoutPreset;
use \Concrete\Core\Area\Layout\CustomLayout as CustomAreaLayout;
use \Concrete\Core\Area\Layout\ThemeGridLayout as ThemeGridAreaLayout;
use \Concrete\Core\Asset\CssAsset;
use URL;
use Page;

class Controller extends BlockController
{

    protected $btSupportsInlineAdd = true;
    protected $btSupportsInlineEdit = true;
    protected $btTable = 'btCoreAreaLayout';
    protected $btIsInternal = true;

    public function getBlockTypeDescription()
    {
        return t("Proxy block for area layouts.");
    }

    public function getBlockTypeName()
    {
        return t("Area Layout");
    }

    public function duplicate($newBID)
    {
        $db = Loader::db();
        parent::duplicate($newBID);
        $ar = AreaLayout::getByID($this->arLayoutID);
        $nr = $ar->duplicate();
        $db->Execute(
            'update btCoreAreaLayout set arLayoutID = ? where bID = ?',
            array($nr->getAreaLayoutID(), $newBID)
        );
    }

    public function getAreaLayoutObject()
    {
        if ($this->arLayoutID) {
            $arLayout = AreaLayout::getByID($this->arLayoutID);
            $b = $this->getBlockObject();
            if (is_object($b)) {
                $arLayout->setBlockObject($b);
            }
            return $arLayout;
        }
    }

    public function delete()
    {
        $arLayout = $this->getAreaLayoutObject();
        if (is_object($arLayout)) {
            $arLayout->delete();
        }
        parent::delete();
    }

    public function export(\SimpleXMLElement $blockNode)
    {
        $layout = $this->getAreaLayoutObject();
        $layout->export($blockNode);
    }

    public function save($post)
    {
        $db = Loader::db();
        $arLayoutID = $db->GetOne('select arLayoutID from btCoreAreaLayout where bID = ?', array($this->bID));
        if (!$arLayoutID) {
            $arLayout = $this->addFromPost($post);
        } else {

            $arLayout = AreaLayout::getByID($arLayoutID);
            // save spacing
            if ($arLayout->isAreaLayoutUsingThemeGridFramework()) {
                $columns = $arLayout->getAreaLayoutColumns();
                for ($i = 0; $i < count($columns); $i++) {
                    $col = $columns[$i];
                    $span = ($post['span'][$i]) ? $post['span'][$i] : 0;
                    $offset = ($post['offset'][$i]) ? $post['offset'][$i] : 0;
                    $col->setAreaLayoutColumnSpan($span);
                    $col->setAreaLayoutColumnOffset($offset);
                }

            } else {
                $arLayout->setAreaLayoutColumnSpacing($post['spacing']);
                if ($post['isautomated']) {
                    $arLayout->disableAreaLayoutCustomColumnWidths();
                } else {
                    $arLayout->enableAreaLayoutCustomColumnWidths();
                    $columns = $arLayout->getAreaLayoutColumns();
                    for ($i = 0; $i < count($columns); $i++) {
                        $col = $columns[$i];
                        $width = ($post['width'][$i]) ? $post['width'][$i] : 0;
                        $col->setAreaLayoutColumnWidth($width);
                    }
                }
            }
        }
        $values = array('arLayoutID' => $arLayout->getAreaLayoutID());
        parent::save($values);
    }

    public function getImportData($blockNode)
    {
        $args = array();
        if (isset($blockNode->arealayout)) {
            $type = (string) $blockNode->arealayout['type'];
            $node = $blockNode->arealayout;
            switch($type) {
                case 'theme-grid':
                    $args['gridType'] = 'TG';
                    $args['arLayoutMaxColumns'] = (string) $node['columns'];
                    $args['themeGridColumns'] = intval(count($node->columns->column));
                    $args['offset'] = array();
                    $args['span'] = array();
                    $i = 0;
                    foreach($node->columns->column as $column) {
                        $args['span'][$i] = intval($column['span']);
                        $args['offset'][$i] = intval($column['offset']);
                        $i++;
                    }
                    break;
                case 'custom':
                    $args['gridType'] = 'FF';
                    $args['isautomated'] = true;
                    $args['spacing'] = intval($node['spacing']);
                    $args['columns'] = intval(count($node->columns->column));
                    $customWidths = intval($node['custom-widths']);
                    if ($customWidths == 1) {
                        $args['isautomated'] = false;
                    }
                    $args['width'] = array();
                    $i = 0;
                    foreach($node->columns->column as $column) {
                        $args['width'][$i] = intval($column['width']);
                        $i++;
                    }
                    break;
            }
        }
        return $args;
    }

    protected function importAdditionalData($b, $blockNode)
    {
        $controller = $b->getController();
        $arLayout = $controller->getAreaLayoutObject();

        $columns = $arLayout->getAreaLayoutColumns();
        $layoutArea = $b->getBlockAreaObject();
        $arLayout->setAreaObject($b->getBlockAreaObject());
        $page = $b->getBlockCollectionObject();

        $i = 0;
        foreach($blockNode->arealayout->columns->column as $columnNode) {
            $column = $columns[$i];
            $as = new SubArea($column->getAreaLayoutColumnDisplayID(), $layoutArea->getAreaHandle(), $layoutArea->getAreaID());
            $as->load($page);
            $column->setAreaID($as->getAreaID());
            $area = $column->getAreaObject();
            foreach($columnNode->block as $bx) {
                $bt = \BlockType::getByHandle($bx['type']);
                if(!is_object($bt)) {
                    throw new \Exception(t('Invalid block type handle: %s', strval($bx['type'])));
                }
                $btc = $bt->getController();
                $btc->import($page, $area->getAreaHandle(), $bx);
            }
            $i++;
        }
    }

    public function addFromPost($post)
    {
        // we are adding a new layout
        switch ($post['gridType']) {
            case 'TG':
                $arLayout = ThemeGridAreaLayout::add();
                $arLayout->setAreaLayoutMaxColumns($post['arLayoutMaxColumns']);
                for ($i = 0; $i < $post['themeGridColumns']; $i++) {
                    $span = ($post['span'][$i]) ? $post['span'][$i] : 0;
                    $offset = ($post['offset'][$i]) ? $post['offset'][$i] : 0;
                    $column = $arLayout->addLayoutColumn();
                    $column->setAreaLayoutColumnSpan($span);
                    $column->setAreaLayoutColumnOffset($offset);
                }
                break;
            case 'FF':
                if ((!$post['isautomated']) && $post['columns'] > 1) {
                    $iscustom = 1;
                } else {
                    $iscustom = 0;
                }
                $arLayout = CustomAreaLayout::add($post['spacing'], $iscustom);
                for ($i = 0; $i < $post['columns']; $i++) {
                    $width = ($post['width'][$i]) ? $post['width'][$i] : 0;
                    $column = $arLayout->addLayoutColumn();
                    $column->setAreaLayoutColumnWidth($width);
                }
                break;
            default: // a preset
                $arLayoutPreset = AreaLayoutPreset::getByID($post['gridType']);
                $arLayout = $arLayoutPreset->getAreaLayoutObject();
                $arLayout = $arLayout->duplicate();
                break;
        }
        return $arLayout;
    }

    public function view()
    {
        $b = $this->getBlockObject();
        $a = $b->getBlockAreaObject();
        $this->arLayout = $this->getAreaLayoutObject();
        if (is_object($this->arLayout)) {
            $this->arLayout->setAreaObject($a);
            $this->set('columns', $this->arLayout->getAreaLayoutColumns());
            $c = Page::getCurrentPage();
            $this->set('c', $c);

            if ($this->arLayout->isAreaLayoutUsingThemeGridFramework()) {
                $pt = $c->getCollectionThemeObject();
                $gf = $pt->getThemeGridFrameworkObject();
            }

            if (isset($gf) && (is_object($gf))) {
                $this->set('gf', $gf);
                $this->render('view_grid');
            } else {
                $asset = new CssAsset();
                $asset->setAssetURL(URL::to('/ccm/system/css/layout', $this->bID));
                $asset->setAssetSupportsMinification(false);
                $asset->setAssetSupportsCombination(false);
                $this->requireAsset($asset);
                $this->render('view');
            }
        } else {
            $this->set('columns', array());
        }
    }

    public function edit()
    {
        $this->addHeaderItem(Loader::helper('html')->javascript('layouts.js'));
        $this->view();
        // since we set a render override in view() we have to explicitly declare edit
        if ($this->arLayout->isAreaLayoutUsingThemeGridFramework()) {
            $c = Page::getCurrentPage();
            $pt = $c->getCollectionThemeObject();
            $gf = $pt->getThemeGridFrameworkObject();
        }
        if (isset($gf) && (is_object($gf))) {
            $this->set('enableThemeGrid', true);
            $this->set('themeGridFramework', $gf);
            $this->set('themeGridMaxColumns', $this->arLayout->getAreaLayoutMaxColumns());
            $this->set('themeGridName', $gf->getPageThemeGridFrameworkName());
            $this->render("edit_grid");
        } else {
            $this->set('enableThemeGrid', false);
            $this->set('spacing', $this->arLayout->getAreaLayoutSpacing());
            $this->set('iscustom', $this->arLayout->hasAreaLayoutCustomColumnWidths());
            $this->set('maxColumns', 12);
            $this->render('edit');
        }
        $this->set('columnsNum', count($this->arLayout->getAreaLayoutColumns()));

    }

    public function add()
    {
        $this->addHeaderItem(Loader::helper('html')->javascript('layouts.js'));
        $maxColumns = 12; // normally
        // now we check our active theme and see if it has other plans
        $c = Page::getCurrentPage();
        $pt = $c->getCollectionThemeObject();
        if (is_object($pt) && $pt->supportsGridFramework() && is_object(
                $this->area
            ) && $this->area->getAreaGridMaximumColumns()
        ) {
            $gf = $pt->getThemeGridFrameworkObject();
            $this->set('enableThemeGrid', true);
            $this->set('themeGridName', $gf->getPageThemeGridFrameworkName());
            $this->set('themeGridFramework', $gf);
            $this->set('themeGridMaxColumns', $this->area->getAreaGridMaximumColumns());
        } else {
            $this->set('enableThemeGrid', false);
        }
        $this->set('columnsNum', 1);
        $this->set('maxColumns', $maxColumns);
    }


}