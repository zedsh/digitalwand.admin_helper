<?php

namespace DigitalWand\AdminHelper\Widget;

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Выпадающий список.
 *
 * Доступные опции:
 * <ul>
 * <li> STYLE - inline-стили</li>
 * <li> VARIANTS - массив с вариантами значений или функция для их получения в формате ключ=>заголовок
 *        Например:
 *            [
 *                1=>'Первый пункт',
 *                2=>'Второй пункт'
 *            ]
 * </li>
 * <li> DEFAULT_VARIANT - ID варианта по-умолчанию</li>
 * </ul>
 */
class ComboBoxWidget extends HelperWidget
{
    static protected $defaults = array(
        'EDIT_IN_LIST' => true,
        'EMPTY_ROW' => true,
        'WITH_FILTER' => false
    );

    protected function jsHelper()
    {
        if($this->jsHelper)
            return true;
        parent::jsHelper();

        ?>
        <script>
            jQuery.fn.filterByText = function (textbox, selectSingleMatch) {
                return this.each(function () {
                    var select = this;
                    var options = [];
                    $(select).find('option').each(function () {
                        options.push({value: $(this).val(), text: $(this).text()});
                    });
                    $(select).data('options', options);
                    $(textbox).bind('change keyup', function () {
                        var current = $(select).val();
                        var options = $(select).empty().scrollTop(0).data('options');
                        var search = $.trim($(this).val());
                        var regex = new RegExp(search, 'gi');

                        $.each(options, function (i) {
                            var option = options[i];
                            if (option.text.match(regex) !== null || option.value === current || option.value === '') {
                                $(select).append(function() {
                                        if (option.value === current) {
                                            return $('<option selected="">').text(option.text).val(option.value);
                                        } else {
                                            return $('<option>').text(option.text).val(option.value);
                                        }
                                    }()
                                );
                            }
                        });
                        if (selectSingleMatch === true &&
                            $(select).children().length === 1) {
                            $(select).children().get(0).selected = true;
                        }
                    });

                    if($.trim(textbox.val()).length !== 0){
                        textbox.change();
                    }
                });
            };
        </script>
        <?

    }

    /**
     * @inheritdoc
     *
     * @see AdminEditHelper::showField();
     *
     * @param bool $forFilter
     *
     * @return mixed
     */
    protected function getEditHtml()
    {
        return $this->getComboBox();
    }

    /**
     * @inheritdoc
     */
    protected function getMultipleEditHtml()
    {
        return $this->getComboBox(true);
    }

    /**
     * Возвращает ХТМЛ-код с комбобоксом.
     *
     * @param bool $multiple Множественный режим.
     * @param bool $forFilter Комбобокс будет выводиться в блоке с фильтром.
     *
     * @return string
     */
    protected function getComboBox($multiple = false, $forFilter = false)
    {
        if ($multiple) {
            $value = $this->getMultipleValue();
        } else {
            $value = $this->getValue();
        }

        $style = $this->getSettings('STYLE');

        $variants = $this->getVariants();


        $empty_row = $this->getSettings('EMPTY_ROW');
        if (!$multiple && $empty_row) {
            array_unshift($variants, array(
                'ID' => null,
                'TITLE' => null
            ));
        }


        if (empty($variants)) {
            $comboBox = Loc::getMessage('DIGITALWAND_AH_MISSING_VARIANTS');
        } else {
            $name = $forFilter ? $this->getFilterInputName() : $this->getEditInputName();
            $filter_list = $this->getSettings('WITH_FILTER');
            $filter_name = $this->getEditId("_FILTER");
            $comboBox = '';

            if ($filter_list) {
               $comboBox .= "<input id='" . $filter_name . "' type='text' placeholder='Поиск по полю'/><br>";
            }


            $edit_id = $this->getEditId();
            $comboBox .= '<select name="' . $name . ($multiple ? '[]' : null) . '"
                ' . ($multiple ? 'multiple="multiple"' : null) . " id='$edit_id'" . ($filter_list ? " size='4' " : "") . '
                style="' . $style . '">';

            foreach ($variants as $variant) {
                $selected = false;

                if ($variant['ID'] == $value) {
                    $selected = true;
                }

                if ($multiple && in_array($variant['ID'], $value)) {
                    $selected = true;
                } elseif ($variant['ID'] === $value) {
                    $selected = true;
                }

                $comboBox .= "<option value='" . static::prepareToTagAttr($variant['ID']) . "' " . ($selected ? "selected" : "") . ">"
                    . static::prepareToTagAttr($variant['TITLE']) . "</option>";
            }

            $comboBox .= '</select>';

            if($filter_list)
            {
                $comboBox .= "<script> $('#' + '$edit_id').filterByText($('#' + '$filter_name'), false); </script>";
                            }
        }

        return $comboBox;
    }

    /**
     * @inheritdoc
     */
    protected function getValueReadonly()
    {
        $variants = $this->getVariants();
        $value = $variants[$this->getValue()]['TITLE'];

        return static::prepareToOutput($value);
    }

    /**
     * @inheritdoc
     */
    protected function getMultipleValueReadonly()
    {
        $variants = $this->getVariants();
        $values = $this->getMultipleValue();
        $result = '';

        if (empty($variants)) {
            $result = Loc::getMessage('DIGITALWAND_AH_MISSING_VARIANTS');
        } else {
            foreach ($variants as $id => $data) {
                $name = strlen($data["TITLE"]) > 0 ? $data["TITLE"] : "";

                if (in_array($id, $values)) {
                    $result .= static::prepareToOutput($name) . '<br/>';
                }
            }
        }

        return $result;
    }

    /**
     * Возвращает массив в следующем формате:
     * <code>
     * array(
     *      '123' => array('ID' => 123, 'TITLE' => 'ololo'),
     *      '456' => array('ID' => 456, 'TITLE' => 'blablabla'),
     *      '789' => array('ID' => 789, 'TITLE' => 'pish-pish'),
     * )
     * </code>
     *
     * Результат будет выводиться в комбобоксе.
     * @return array
     */
    protected function getVariants()
    {
        $variants = $this->getSettings('VARIANTS');

        if (is_callable($variants)) {
            $var = $variants();

            if (is_array($var)) {
                return $this->formatVariants($var);
            }
        } elseif (is_array($variants) AND !empty($variants)) {
            return $this->formatVariants($variants);
        }

        return array();
    }

    /**
     * Приводит варианты к нужному формату, если они заданы в виде одномерного массива.
     *
     * @param $variants
     *
     * @return array
     */
    protected function formatVariants($variants)
    {
        $formatted = array();

        foreach ($variants as $id => $data) {
            if (!is_array($data)) {
                $formatted[$id] = array(
                    'ID' => $id,
                    'TITLE' => $data
                );
            }
        }

        return $formatted;
    }

    /**
     * @inheritdoc
     */
    public function generateRow(&$row, $data)
    {
        if ($this->settings['EDIT_IN_LIST'] AND !$this->settings['READONLY']) {
            $row->AddInputField($this->getCode(), array('style' => 'width:90%'));
        } else {
            $row->AddViewField($this->getCode(), $this->getValueReadonly());
        }
    }

    /**
     * @inheritdoc
     */
    public function showFilterHtml()
    {
        print '<tr>';
        print '<td>' . $this->getSettings('TITLE') . '</td>';
        print '<td>' . $this->getComboBox(false, true) . '</td>';
        print '</tr>';
    }

    /**
     * @inheritdoc
     */
    public function processEditAction()
    {
        if ($this->getSettings('MULTIPLE')) {
            $sphere = $this->data[$this->getCode()];
            unset($this->data[$this->getCode()]);

            foreach ($sphere as $sphereKey) {
                $this->data[$this->getCode()][] = array('VALUE' => $sphereKey);
            }
        }

        parent::processEditAction();
    }
}
