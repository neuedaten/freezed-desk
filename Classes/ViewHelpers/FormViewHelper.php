<?php

namespace Neuedaten\FreezedDesk\ViewHelpers;

use Neuedaten\Freezed\Services\ConfigService;
use Neuedaten\FreezedDesk\DeskContext;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Renders a form of the website from its definition in desk/forms/, for
 * templates of the site:
 *
 *     <html xmlns:desk="http://typo3.org/ns/Neuedaten/FreezedDesk/ViewHelpers">
 *     <desk:form name="report" action="/api/v1/report" item="{slug}" />
 *
 * The markup is plain and unstyled: a <form> with one <p class="field">
 * per field, a honeypot input, a hidden token field the endpoint fills
 * (data-token-url) and a submit button. A project that wants its own
 * markup overrides the partial "Desk/Form" in its theme; the ViewHelper
 * then renders that partial with the form definition as "form".
 *
 * Arguments:
 *   name      The form (desk/forms/<name>.php).
 *   action    URL the form posts to (default: desk.inbox.formAction or "/api/v1/<name>").
 *   item      Value for the form's item field (e.g. the record's slug), hidden.
 *   class     CSS class of the <form>.
 *   submit    Label of the submit button.
 */
class FormViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        $this->registerArgument('name', 'string', 'Form name', true);
        $this->registerArgument('action', 'string', 'Form action URL', false);
        $this->registerArgument('item', 'string', 'Value of the form\'s item field', false);
        $this->registerArgument('class', 'string', 'CSS class', false, 'form');
        $this->registerArgument('submit', 'string', 'Submit button label', false, 'Absenden');
    }

    public function render(): string
    {
        $context = DeskContext::get(readOnly: true);
        $definition = $context->forms()->get((string) $this->arguments['name']);

        $action = $this->arguments['action'];
        if (!is_string($action) || $action === '') {
            $base = (string) ($context->config->get('inbox.formAction') ?? '/api/v1');
            $action = rtrim($base, '/') . '/' . $definition->name;
        }

        $variables = [
            'form' => $definition->declaration + ['name' => $definition->name],
            'action' => $action,
            'item' => $this->arguments['item'],
            'class' => $this->arguments['class'],
            'submit' => $this->arguments['submit'],
        ];

        // A project partial wins over the built-in markup.
        try {
            $partial = $this->renderingContext->getTemplatePaths()->getPartialPathAndFilename('Desk/Form');
            if (is_file($partial)) {
                return $this->renderingContext->getViewHelperVariableContainer()->getView()?->renderPartial('Desk/Form', null, $variables) ?? $this->markup($definition, $variables);
            }
        } catch (\Throwable) {
            // no partial: built-in markup
        }

        return $this->markup($definition, $variables);
    }

    /** @param array<string, mixed> $v */
    private function markup(\Neuedaten\FreezedDesk\Inbox\FormDefinition $definition, array $v): string
    {
        $e = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5);
        $tokenUrl = rtrim(dirname((string) $v['action']), '/') . '/token';

        $html = sprintf(
            '<form class="%s" method="post" action="%s" data-desk-form="%s" data-token-url="%s">' . "\n",
            $e($v['class']),
            $e($v['action']),
            $e($definition->name),
            $e($tokenUrl)
        );
        $html .= '<input type="hidden" name="_token" value="">' . "\n";
        $html .= sprintf('<p class="field field--hp" aria-hidden="true" style="position:absolute;left:-9999px"><label>Website <input type="text" name="%s" tabindex="-1" autocomplete="off"></label></p>' . "\n", $e($definition->honeypot));

        foreach ($definition->fields() as $name => $field) {
            $type = (string) ($field['type'] ?? 'text');
            $label = (string) ($field['label'] ?? $name);
            $required = !empty($field['required']) ? ' required' : '';
            $id = 'f-' . $definition->name . '-' . $name;

            if ($name === $definition->itemField && $v['item'] !== null) {
                $html .= sprintf('<input type="hidden" name="%s" value="%s">' . "\n", $e($name), $e($v['item']));
                continue;
            }

            $html .= '<p class="field field--' . $e($type) . '">';
            switch ($type) {
                case 'textarea':
                    $html .= sprintf('<label for="%1$s">%2$s</label><textarea id="%1$s" name="%3$s" rows="5"%4$s%5$s></textarea>', $e($id), $e($label), $e($name), $required, isset($field['maxLength']) ? ' maxlength="' . (int) $field['maxLength'] . '"' : '');
                    break;
                case 'select':
                    $html .= sprintf('<label for="%1$s">%2$s</label><select id="%1$s" name="%3$s%4$s"%5$s%6$s>', $e($id), $e($label), $e($name), !empty($field['multiple']) ? '[]' : '', $required, !empty($field['multiple']) ? ' multiple' : '');
                    if (empty($field['multiple']) && empty($field['required'])) {
                        $html .= '<option value=""></option>';
                    }
                    require_once dirname(__DIR__, 2) . '/server/api/lib/FormRules.php';
                    foreach (desk_form_options($field) as $key => $optionLabel) {
                        $html .= sprintf('<option value="%s">%s</option>', $e($key), $e($optionLabel));
                    }
                    $html .= '</select>';
                    break;
                case 'bool':
                    $html .= sprintf('<label><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s> %4$s</label>', $e($id), $e($name), $required, $e($label));
                    break;
                default:
                    $inputType = in_array($type, ['email', 'url', 'number', 'date'], true) ? $type : 'text';
                    $html .= sprintf('<label for="%1$s">%2$s</label><input type="%3$s" id="%1$s" name="%4$s"%5$s%6$s>', $e($id), $e($label), $inputType, $e($name), $required, isset($field['maxLength']) ? ' maxlength="' . (int) $field['maxLength'] . '"' : '');
            }
            $html .= "</p>\n";
        }

        $html .= sprintf('<p class="field field--submit"><button type="submit">%s</button></p>' . "\n</form>\n", $e($v['submit']));

        return $html;
    }
}
