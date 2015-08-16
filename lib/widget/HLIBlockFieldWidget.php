<?php
namespace DigitalWand\AdminHelper\Widget;

use DigitalWand\AdminHelper\Helper\AdminBaseHelper;
use Bitrix\Main\Entity\DataManager;
use Bitrix\Main\Entity\EntityError;
use Bitrix\Main\Entity\Result;
use Bitrix\Highloadblock as HL;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Виджет, отображающийстандартные поля, создаваемые в HL-инфоблоке в админке.
 *
 * Настройки:
 * <ul>
 * <li><b>MODEL</b> - Название модели, из которой будет производиться выборка данных. По-умолчанию - модель текущего
 * хэлпера</li>
 * </ul>
 * Class HLIBlockFieldWidget
 * @package DigitalWand\AdminHelper\Widget
 */
class HLIBlockFieldWidget extends HelperWidget
{
    static protected $userFieldsCache = array();
    static protected $defaults = array(
        'USE_BX_API' => true
    );

    /**
     * Генерирует HTML для редактирования поля
     *
     * @see \CAdminForm::ShowUserFieldsWithReadyData
     * @return mixed
     */
    protected function genEditHTML()
    {
        $info = $this->getUserFieldInfo();
        if ($info) {

            /** @var \CAllUserTypeManager $USER_FIELD_MANAGER */
            global $USER_FIELD_MANAGER;
            $FIELD_NAME = $this->getCode();
            $GLOBALS[$FIELD_NAME] = isset($GLOBALS[$FIELD_NAME]) ? $GLOBALS[$FIELD_NAME] : $this->data[$this->getCode()];
            $bVarsFromForm = false;

            $info["VALUE_ID"] = intval($this->data['ID']);
            $info['EDIT_FORM_LABEL'] = $this->getSettings('TITLE');

            if (isset($_REQUEST['def_' . $FIELD_NAME])) {
                $info['SETTINGS']['DEFAULT_VALUE'] = $_REQUEST['def_' . $FIELD_NAME];
            }
            print $USER_FIELD_MANAGER->GetEditFormHTML($bVarsFromForm, $GLOBALS[$FIELD_NAME], $info);

        }
    }

    /**
     * @see Bitrix\Highloadblock\DataManager
     * @see /bitrix/modules/highloadblock/admin/highloadblock_row_edit.php
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\SystemException
     */
    public function processEditAction()
    {
        /** @var \CAllUserTypeManager $USER_FIELD_MANAGER */
        global $USER_FIELD_MANAGER;
        global $APPLICATION;
        $iblockId = 'HLBLOCK_' . $this->getHLId();

        $data = $this->data;
        $USER_FIELD_MANAGER->EditFormAddFields($iblockId, $data);

        $entity_data_class = AdminBaseHelper::getHLEntity($this->getSettings('MODEL'));

        $oldData = $this->getOldFieldData($entity_data_class);
        $fields = $USER_FIELD_MANAGER->getUserFieldsWithReadyData($iblockId, $oldData, LANGUAGE_ID, false, 'ID');
        list($data, $multiValues) = $this->convertValuesBeforeSave($data, $fields);
        // use save modifiers
        foreach ($data as $fieldName => $value) {
            $field = $entity_data_class::getEntity()->getField($fieldName);
            $data[$fieldName] = $field->modifyValueBeforeSave($value, $data);
        }

        //Чтобы не терялись старые данные
        if (!isset($data[$this->getCode()]) AND isset($data[$this->getCode() . '_old_id'])) {
            $data[$this->getCode()] = $data[$this->getCode() . '_old_id'];
        }

        if ($unserialized = unserialize($data[$this->getCode()])) {
            $this->data[$this->getCode()] = $unserialized;
        } else {
            $this->data[$this->getCode()] = $data[$this->getCode()];
        }
    }

    /**
     * Битриксу надо получить поля, кторые сохранены в базе для этого пользовательского свойства.
     * Иначе множественные свойства он затрёт.
     * Проблема в том, что пользовательские свойства могут браться из связанной сущности.
     * @param HL\DataManager $entity_data_class
     *
     * @return mixed
     */
    protected function getOldFieldData($entity_data_class)
    {
        if (is_null($this->data) OR !isset($this->data[$this->helper->pk()])) return false;
        return $entity_data_class::getByPrimary($this->data[$this->helper->pk()])->fetch();
    }

    /**
     * @see Bitrix\Highloadblock\DataManager::convertValuesBeforeSave
     * @param $data
     * @param $userfields
     *
     * @return array
     */
    protected function convertValuesBeforeSave($data, $userfields)
    {
        $multiValues = array();

        foreach ($data as $k => $v) {
            if ($k == 'ID') {
                continue;
            }

            $userfield = $userfields[$k];

            if ($userfield['MULTIPLE'] == 'N') {
                $inputValue = array($v);
            } else {
                $inputValue = $v;
            }

            $tmpValue = array();

            foreach ($inputValue as $singleValue) {
                $tmpValue[] = $this->convertSingleValueBeforeSave($singleValue, $userfield);
            }

            // write value back
            if ($userfield['MULTIPLE'] == 'N') {
                $data[$k] = $tmpValue[0];
            } else {
                // remove empty (false) values
                $tmpValue = array_filter($tmpValue, 'strlen');

                $data[$k] = $tmpValue;
                $multiValues[$k] = $tmpValue;
            }
        }

        return array($data, $multiValues);
    }

    /**
     * @see Bitrix\Highloadblock\DataManager::convertSingleValueBeforeSave
     * @param $value
     * @param $userfield
     *
     * @return bool|mixed
     */
    protected function convertSingleValueBeforeSave($value, $userfield)
    {
        if (is_callable(array($userfield["USER_TYPE"]["CLASS_NAME"], "onbeforesave"))) {
            $value = call_user_func_array(
                array($userfield["USER_TYPE"]["CLASS_NAME"], "onbeforesave"), array($userfield, $value)
            );
        }

        if (strlen($value) <= 0) {
            $value = false;
        }

        return $value;
    }

    /**
     * Если запрашивается модель, и если модель явно не указана, то берется модель текущего хэлпера, сохраняется для
     * последующего использования и возарвщвется пользователю.
     *
     * @param string $name
     * @return array|\Bitrix\Main\Entity\DataManager|mixed|string
     */
    public function getSettings($name = '')
    {
        $value = parent::getSettings($name);
        if (!$value) {
            if ($name == 'MODEL') {
                $value = $this->helper->getModel();
                $this->setSetting($name, $value);

            } else if ($name == 'TITLE') {

                $info = $this->getUserFieldInfo();
                if (isset($info['LIST_COLUMN_LABEL']) AND !empty($info['LIST_COLUMN_LABEL'])) {
                    $value = $info['LIST_COLUMN_LABEL'];
                } else {
                    $value = $info['FIELD_NAME'];
                }
                $this->setSetting('TITLE', $value);
            }
        }
        return $value;
    }


    /**
     * Генерирует HTML для поля в списке
     * Копипаст из API Битрикса, бессмысленного и беспощадного...
     *
     * @see AdminListHelper::addRowCell();
     *
     * @param \CAdminListRow $row
     * @param array $data - данные текущей строки
     *
     * @return mixed
     */
    public function genListHTML(&$row, $data)
    {
        $info = $this->getUserFieldInfo();
        if ($info) {

            /** @var \CAllUserTypeManager $USER_FIELD_MANAGER */
            global $USER_FIELD_MANAGER;
            $FIELD_NAME = $this->getCode();
            $GLOBALS[$FIELD_NAME] = isset($GLOBALS[$FIELD_NAME]) ? $GLOBALS[$FIELD_NAME] : $this->data[$this->getCode()];

            $info["VALUE_ID"] = intval($this->data['ID']);

            if (isset($_REQUEST['def_' . $FIELD_NAME])) {
                $info['SETTINGS']['DEFAULT_VALUE'] = $_REQUEST['def_' . $FIELD_NAME];
            }
            $USER_FIELD_MANAGER->AddUserField($info, $data[$this->getCode()], $row);

        }
    }

    /**
     * Генерирует HTML для поля фильтрации
     *
     * @see AdminListHelper::createFilterForm();
     * @return mixed
     */
    public function genFilterHTML()
    {
        $info = $this->getUserFieldInfo();
        if ($info) {
            /** @var \CAllUserTypeManager $USER_FIELD_MANAGER */
            global $USER_FIELD_MANAGER;
            $FIELD_NAME = $this->getCode();
            $GLOBALS[$FIELD_NAME] = isset($GLOBALS[$FIELD_NAME]) ? $GLOBALS[$FIELD_NAME] : $this->data[$this->getCode()];

            $info["VALUE_ID"] = intval($this->data['ID']);
            $info['LIST_FILTER_LABEL'] = $this->getSettings('TITLE');

            print $USER_FIELD_MANAGER->GetFilterHTML($info, $this->getFilterInputName(), $this->getCurrentFilterValue());
        }
    }

    public function getUserFieldInfo()
    {
        $id = $this->getHLId();
        $fields = static::getUserFields($id, $this->data);
        if (isset($fields[$this->getCode()])) {
            return $fields[$this->getCode()];
        }
        return false;
    }

    /**
     * Получаем ID HL-инфоблока по имени его класса
     * @return mixed
     */
    protected function getHLId()
    {
        static $id = false;

        if ($id === false) {
            $model = $this->getSettings('MODEL');
            $info = AdminBaseHelper::getHLEntityInfo($model);
            if ($info AND isset($info['ID'])) {
                $id = $info['ID'];
            }
        }

        return $id;
    }

    static public function getUserFields($iblockId, $data)
    {
        /** @var \CAllUserTypeManager $USER_FIELD_MANAGER */
        global $USER_FIELD_MANAGER;
        $iblockId = 'HLBLOCK_' . $iblockId;
        if (!isset(static::$userFieldsCache[$iblockId][$data['ID']])) {
            $fields = $USER_FIELD_MANAGER->getUserFieldsWithReadyData($iblockId, $data, LANGUAGE_ID, false, 'ID');
            self::$userFieldsCache[$iblockId][$data['ID']] = $fields;
        }

        return self::$userFieldsCache[$iblockId][$data['ID']];
    }
}