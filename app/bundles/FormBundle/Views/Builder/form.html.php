<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
/** @var \Mautic\FormBundle\Entity\Form $form */

use Mautic\FormBundle\Collection\MappedObjectCollection;

$formName = '_'.$form->generateFormName().(isset($suffix) ? $suffix : '');
if (!isset($fields)) {
    $fields = $form->getFields();
}
$pageCount = 1;

if (!isset($inBuilder)) {
    $inBuilder = false;
}

if (!isset($action)) {
    $action = $view['router']->url('mautic_form_postresults', ['formId' => $form->getId()]);
}

if (!isset($theme)) {
    $theme = '';
}

if (!isset($mappedFields)) {
    $mappedFields = new MappedObjectCollection();
}

if (!isset($style)) {
    $style = '';
}

if (!isset($isAjax)) {
    $isAjax = true;
}

if (!isset($submissions)) {
    $submissions = null;
}

if (!isset($lead)) {
    $lead = null;
}
?>

<?php echo $style; ?>
<style type="text/css" scoped>
    .mauticform-field-hidden { display:none }
</style>

<div id="mauticform_wrapper<?php echo $formName; ?>" class="mauticform_wrapper">
    <form autocomplete="false" role="form" method="post" action="<?php echo  $action; ?>" id="mauticform<?php echo $formName; ?>" <?php if ($isAjax): ?> data-mautic-form="<?php echo ltrim($formName, '_'); ?>"<?php endif; ?> enctype="multipart/form-data" <?php echo $form->getFormAttributes(); ?>>
        <div class="mauticform-error" id="mauticform<?php echo $formName; ?>_error"></div>
        <div class="mauticform-message" id="mauticform<?php echo $formName; ?>_message"></div>
        <div class="mauticform-innerform">
            <?php
            $displayManager = new \Mautic\FormBundle\ProgressiveProfiling\DisplayManager(
                $form,
                !empty($viewOnlyFields) ? $viewOnlyFields : []
            );
            /** @var \Mautic\FormBundle\Entity\Field $f */
            foreach ($fields as $fieldId => $f):
                if (isset($formPages['open'][$fieldId])):
                    // Start a new page
                    $lastFieldAttribute = ($lastFormPage === $fieldId) ? ' data-mautic-form-pagebreak-lastpage="true"' : '';
                    echo "\n          <div class=\"mauticform-page-wrapper mauticform-page-$pageCount\" data-mautic-form-page=\"$pageCount\"$lastFieldAttribute>\n";
                endif;

                if (!$f->getParent() && $f->showForContact($submissions, $lead, $form, $displayManager)):
                    if ($f->isCustom()):
                        if (!isset($fieldSettings[$f->getType()])):
                            continue;
                        endif;
                        $params = $fieldSettings[$f->getType()];
                        $f->setCustomParameters($params);

                        $template = $params['template'];
                    else:
                        if (!$f->isAlwaysDisplay()
                            && !$f->getShowWhenValueExists()
                            && $f->getMappedField()
                            && 'contact' === $f->getMappedObject()
                            && $f->getIsAutoFill()
                            && $lead
                            && !empty($lead->getFieldValue($f->getMappedField()))
                        ) {
                            $f->setType('hidden');
                        } else {
                            $displayManager->increaseDisplayedFields($f);
                        }
                        $template = 'MauticFormBundle:Field:'.$f->getType().'.html.php';
                    endif;

                    echo $view->render(
                        $theme.$template,
                        [
                            'field'         => $f->convertToArray(),
                            'id'            => $f->getAlias(),
                            'formName'      => $formName,
                            'fieldPage'     => ($pageCount - 1), // current page,
                            'mappedFields'  => $mappedFields,
                            'inBuilder'     => $inBuilder,
                            'fields'        => $fields,
                        ]
                    );
                endif;
                $parentField = $f;
                foreach ($fields as $fieldId2 => $f):
                    if ('hidden' !== $parentField->getType() && $f->getParent() == $parentField->getId()):
                    if ($f->isCustom()):
                        if (!isset($fieldSettings[$f->getType()])):
                            continue;
                        endif;
                        $params = $fieldSettings[$f->getType()];
                        $f->setCustomParameters($params);

                        $template = $params['template'];
                    else:
                        if (!$f->getShowWhenValueExists()
                            && $f->getMappedField()
                            && 'contact' === $f->getMappedObject()
                            && $f->getIsAutoFill()
                            && $lead
                            && !empty($lead->getFieldValue($f->getMappedField()))
                        ) {
                            $f->setType('hidden');
                        }
                        $template = 'MauticFormBundle:Field:'.$f->getType().'.html.php';
                    endif;

                    echo $view->render(
                        $theme.$template,
                        [
                            'field'         => $f->convertToArray(),
                            'id'            => $f->getAlias(),
                            'formName'      => $formName,
                            'fieldPage'     => ($pageCount - 1), // current page,
                            'mappedFields'  => $mappedFields,
                            'inBuilder'     => $inBuilder,
                            'fields'        => $fields,
                        ]
                    );
                    endif;
                endforeach;

                if (isset($formPages) && isset($formPages['close'][$fieldId])):
                    // Close the page
                    echo "\n            </div>\n";
                    ++$pageCount;
                endif;

            endforeach;
            ?>
        </div>

        <input type="hidden" name="mauticform[formId]" id="mauticform<?php echo $formName; ?>_id" value="<?php echo $view->escape($form->getId()); ?>"/>
        <input type="hidden" name="mauticform[return]" id="mauticform<?php echo $formName; ?>_return" value=""/>
        <input type="hidden" name="mauticform[formName]" id="mauticform<?php echo $formName; ?>_name" value="<?php echo $view->escape(ltrim($formName, '_')); ?>"/>

        <?php echo (isset($formExtra)) ? $formExtra : ''; ?>
</form>
</div>
