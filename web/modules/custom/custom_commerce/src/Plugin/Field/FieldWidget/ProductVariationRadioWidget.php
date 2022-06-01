<?php

namespace Drupal\custom_commerce\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\commerce_product\Plugin\Field\FieldWidget\ProductVariationWidgetBase;

/**
 * Plugin implementation of the 'commerce_product_variation_radio' widget.
 *
 * @FieldWidget(
 *   id = "commerce_product_variation_radio",
 *   label = @Translation("Product variation radio"),
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 */
class ProductVariationRadioWidget extends ProductVariationWidgetBase implements ContainerFactoryPluginInterface {

  /**
   * Variation entity type.
   *
   * @var string
   */
  protected $variationEntityType = 'commerce_product_variation';

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
        'label_display' => TRUE,
        'label_text' => 'Please select',
        'hide_single' => TRUE,
        'label_display_mode' => 'default',
      ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element = parent::settingsForm($form, $form_state);
    $element['label_display'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Display label'),
      '#default_value' => $this->getSetting('label_display'),
    ];
    $element['label_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label text'),
      '#default_value' => $this->getSetting('label_text'),
      '#description' => $this->t('The label will be available to screen readers even if it is not displayed.'),
      '#required' => TRUE,
    ];
    $element['hide_single'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Hide if there's only one product variation"),
      '#default_value' => $this->getSetting('hide_single'),
    ];
    if (isset($form['#entity_type']) && $entity_type = $form['#entity_type']) {
      $nodeEntity = \Drupal::service('entity_display.repository');
      $entity_type = $this->variationEntityType;
      $modes = $nodeEntity->getViewModes($entity_type);

      $mode_options = ['default' => $this->t('Default')];
      $mode_options += array_map(function ($n) {
        return $n['label'];
      }, $modes);

      $element['label_display_mode'] = [
        '#type' => 'select',
        '#title' => $this->t('Choose a display mode'),
        '#default_value' => $this->getSetting('label_display_mode'),
        '#options' => $mode_options,
      ];
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    $summary[] = $this->t('Label: "@text" (@visible)', [
      '@text' => $this->getSetting('label_text'),
      '@visible' => $this->getSetting('label_display') ? $this->t('visible') : $this->t('hidden'),
    ]);
    if ($this->getSetting('hide_single')) {
      $summary[] = $this->t("Hidden if there's only one product variation.");
    }

    if ($this->getSetting('label_display_mode')) {
      $summary[] = $this->t('Label display mode: @mode', ['@mode' => $this->getSetting('label_display_mode')]);
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\commerce_product\Entity\ProductInterface $product */
    $product = $form_state->get('product');
    $variations = $this->loadEnabledVariations($product);
    if (count($variations) === 0) {
      // Nothing to purchase, tell the parent form to hide itself.
      $form_state->set('hide_form', TRUE);
      $element['variation'] = [
        '#type' => 'value',
        '#value' => 0,
      ];
      return $element;
    }
    elseif (count($variations) === 1 && $this->getSetting('hide_single')) {
      /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $selected_variation */
      $selected_variation = reset($variations);
      $element['variation'] = [
        '#type' => 'value',
        '#value' => $selected_variation->id(),
      ];
      return $element;
    }

    // Build the variation options form.
    $wrapper_id = Html::getUniqueId('commerce-product-add-to-cart-form');
    $form += [
      '#wrapper_id' => $wrapper_id,
      '#prefix' => '<div id="' . $wrapper_id . '">',
      '#suffix' => '</div>',
    ];
    $parents = array_merge(
      $element['#field_parents'],
      [$items->getName(), $delta]
    );
    $user_input = (array) NestedArray::getValue($form_state->getUserInput(), $parents);
    if (!empty($user_input)) {
      $selected_variation = $this->selectVariationFromUserInput($variations, $user_input);
    }
    else {
      $selected_variation = $this->getDefaultVariation($product, $variations);
    }

    // Set the selected variation in the form state for our AJAX callback.
    $form_state->set('selected_variation', $selected_variation->id());

    $variation_options = [];
    $view_builder = \Drupal::entityTypeManager()->getViewBuilder($this->variationEntityType);
    foreach ($variations as $option) {
      $entity = $option;
      $pre_render = $view_builder->view($entity, $this->getSetting('label_display_mode'));
      //$render_output = render($pre_render);
      $render_output = \Drupal::service('renderer')->render($pre_render);
      
      // $variation_options[$option->id()] = $option->label();
      $variation_options[$option->id()] = $render_output;
    }
    $element['variation'] = [
      '#type' => 'radios',
      '#title' => $this->getSetting('label_text'),
      '#options' => $variation_options,
      '#required' => TRUE,
      '#default_value' => $selected_variation->id(),
      '#ajax' => [
        'callback' => [get_class($this), 'ajaxRefresh'],
        'wrapper' => $form['#wrapper_id'],
      ],
    ];
    if ($this->getSetting('label_display') == FALSE) {
      $element['variation']['#title_display'] = 'invisible';
    }

    $element['variation']['#context']['widget'] = 'commerce_product_variation_radio';

    return $element;
  }

  /**
   * Selects a product variation from user input.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface[] $variations
   *   An array of product variations.
   * @param array $user_input
   *   The user input.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface|null
   *   The selected variation or NULL if there's
   *   no user input (form viewed for the first time).
   */
  protected function selectVariationFromUserInput(array $variations, array $user_input) {
    $current_variation = NULL;
    if (!empty($user_input['variation']) && $variations[$user_input['variation']]) {
      $current_variation = $variations[$user_input['variation']];
    }

    return $current_variation;
  }

}
