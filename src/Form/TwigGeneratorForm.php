<?php

namespace Drupal\twig_generator\Form;

use Drupal\Component\Utility\SortArray;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class TwigGeneratorForm
 * TODO: Create a Drush command to export using CLI instead of UI
 * TODO: Options per Entity Type (comment, replace, view modes)
 * TODO: To be able to change the "original" template and specify what to replace (partially done with conf.)
 * TODO: Being able to export custom content types (partially done with conf.)
 * TODO: Save settings in Conf/State
 * TODO: Is there a way to get the default template programmatically ?
 * TODO: We might not want to exclude Fields with no definition... (like 'links')
 *
 * @package Drupal\twig_generator\Form
 */
class TwigGeneratorForm extends FormBase {

  protected $entityTypeIds = [];

  protected $typeToTpl = [];

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The entity display objects repository manager.
   * (view modes and form modes)
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   */
  protected $entityDisplayRepository;

  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * Class constructor.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityDisplayRepositoryInterface $entity_display_repository
   *   The entity display repository.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, EntityDisplayRepositoryInterface $entity_display_repository, EntityTypeBundleInfoInterface $entity_type_bundle_info) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityDisplayRepository = $entity_display_repository;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // Instantiates this form class.
    return new static(
      // Load the services required to construct this class.
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('entity_display.repository'),
      $container->get('entity_type.bundle.info')
    );
  }


  public function getFormId() {
    return 'twig_generator_form';
  }

  private function getTemplatePath($entity_type_id) {
    // Use the default template if there is one
    if (array_key_exists($entity_type_id, $this->typeToTpl)) {
      return $this->typeToTpl[$entity_type_id];
    }

    return '';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {

    $config = $this->config('twig_generator.settings');  // getEditable if we want to be able to set & save
    // Get default associations
    $this->typeToTpl = $config->get('type_to_tpl');
    // Entity Type Ids to export
    $this->entityTypeIds = $config->get('entity_type_ids');

    // GLOBAL SETTINGS
    // ===============

    $form['add_comment'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Add fields in comment'),
      '#default_value' => TRUE,
    );

    $form['replace_content'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Replace {{ content }} with fields'),
      '#default_value' => TRUE,
    );

    $form['view_modes'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Generate ALL View Modes for each Bundle'),
      '#default_value' => TRUE,
    );

    $form['excluded_fields'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Fields to exclude (space separated machine names)'),
      '#description' => $this->t('Those fields will be excluded from the TWIG.'),
      '#default_value' => 'field_metatags',
    );


    // SETTINGS PER ENTITY TYPE
    // ========================

    // For each entity type get their bundles and list them
    foreach($this->entityTypeIds as $entityTypeId) {

      $entityType = $this->entityTypeManager->getDefinition($entityTypeId, FALSE);

      if (isset($entityType) && $entityType->entityClassImplements(FieldableEntityInterface::class)) {
        // Fieldset
        $form[$entityTypeId] = [
          '#type' => 'details',
          '#title' => $entityType->getBundleLabel(),
        ];
        // destination directory
        $form[$entityTypeId][$entityTypeId . '_tpl'] = [
          '#type' => 'textfield',
          '#title' => $this->t('%type default template', ['%type' => $entityType->getBundleLabel()]),
          '#description' => $this->t('Path of the template to use.'),
          '#default_value' => $this->getTemplatePath($entityTypeId),
          '#required' => TRUE,
        ];
        // destination directory
        $form[$entityTypeId][$entityTypeId . '_dest'] = [
          '#type' => 'textfield',
          '#title' => $this->t('%type export directory', ['%type' => $entityType->getBundleLabel()]),
          '#description' => $this->t('The directory where files will be created. Directory will be created if not exists.'),
          '#default_value' => "modules/templates/" . $entityTypeId,
          '#required' => TRUE,
        ];
        $bundleHeader = ['bundle' => t('Bundles')];
        // Initialize an empty array
        $table = [];
        $bundles = $this->entityTypeBundleInfo->getBundleInfo($entityTypeId);
        foreach (array_keys($bundles) as $bundle) {
          $table[$bundle] = [
            'bundle' => $bundle,
          ];
        }
        $form[$entityTypeId][$entityTypeId . '_table'] = [
          '#type' => 'tableselect',
          '#header' => $bundleHeader,
          '#options' => $table,
          '#empty' => t('No Bundle found'),
          '#attributes' => ['checked' => TRUE], // To select all by default
        ];
      }
      // Remove type from the list
      else {
        if (($key = array_search($entityTypeId, $this->entityTypeIds)) !== FALSE) {
          unset($this->entityTypeIds[$key]);
        }
      }
    }


    // ACTIONS
    // =======

    // Group submit handlers in an actions element with a key of "actions" so
    // that it gets styled correctly, and so that other modules may add actions
    // to the form. This is not required, but is convention.
    $form['actions'] = [
      '#type' => 'actions',
    ];

    // Add a submit button that handles the submission of the form.
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate'),
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
//    parent::validateForm($form, $form_state);

    $values = $form_state->getValues();

    if (!isset($values['node_dest'])) {
      // Set an error for the form element with a key of "path".
      $form_state->setErrorByName('node_dest', $this->t('You must set a path!'));
    }
/*
    if (!isset($values['paragraph_dest'])) {
      // Set an error for the form element with a key of "path".
      $form_state->setErrorByName('paragraph_dest', $this->t('You must set a path!'));
    }
*/
  }

  /**
   * Generate a comment to describe the given field definition.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface
   *   The field definition.
   *
   * @return string
   *   Description of the field ot insert into the comment section of the TWIG
   */
  private function generateFieldComment(FieldDefinitionInterface $definition) {
    $lineStart = ' * ';
    $comment  = $lineStart . '- ' . $definition->getName() . ":" . "\n";
    $comment .= $lineStart . "  | type: " . $definition->getType() . "\n";
    $comment .= $lineStart . "  | cardinality: " . $definition->getFieldStorageDefinition()->getCardinality() . "\n";
    if ($definition->isRequired())
      $comment .= $lineStart . "  | required" . "\n";
    return $comment;
  }


  /**
   * @param array $fields
   * @param $options
   *
   * @return array
   *   Associative array with 'comment' and 'fields'
   */
  private function getFieldsInfo(array $field_definitions, $options) {
    $comment = "";
    $fields = "";
    $indent = isset($options['indent']) ? $options['indent'] : "  ";

    foreach ($field_definitions as $name => $definition) {

      // Add Field to comment block if needed
      if (!empty($options['addComment']))
        $comment .= $this->generateFieldComment($definition);

      if (!empty($options['replaceContent']))
        $fields .= ("\n" . $indent . "{{ content.".$definition->getName() . " }}" . " {# ". $definition->getType() . '(max: ' . $definition->getFieldStorageDefinition()->getCardinality() . ')' .  " #}");
    }

    return [
      'comment' => $comment,
      'fields' => $fields
    ];
  }

  private function updateContent($original_content, $fields_info, $options) {

    $content = $original_content;
    // Replace {{ content }} with fields if needed
    if (!empty($options['replaceContent'])) {
      $content = preg_replace('/\n\s+{{ content }}/', $fields_info['fields'], $content);
    }
    // Add Comment to describe fields
    if (!empty($options['addComment'])) {
      $comment = " * Available fields:" . "\n" . " *   You can print them like this: {{ content.[fieldname] }}" . "\n" . $fields_info['comment'] . ' *' . "\n";
      $content = preg_replace('/(.*Available variables:)/', $comment . "$1", $content);
    }
    return $content;
  }

  /**
   * @param $entity_type_id
   * @param $bundle
   * @param $view_mode
   * @param $excluded
   *
   * @return array
   *   Associative array with fieldnames as keys and definitions as values
   */
  private function getDefinitionsFromViewMode($entity_type_id, $bundle, $view_mode, $excluded) {

    // Get information about the display ('default' view mode)
    $viewDisp = $this->entityTypeManager
      ->getStorage('entity_view_display')
      ->load($entity_type_id . '.' . $bundle . '.' . $view_mode);
    // If we wanted to get other view modes :
    // $this->entityDisplayRepository->getViewModeOptionsByBundle('node', 'content_page')
    // $this->entityDisplayRepository->getAllViewModes()
    // $this->entityDisplayRepository->getViewModes('node')

    // Get Fields definition
    $fieldDef = $viewDisp->get('fieldDefinitions');
    // Get fields not hidden
    $orderedFields = $viewDisp->get('content');
    // Ordered by Weight
    uasort($orderedFields, ['Drupal\Component\Utility\SortArray', 'sortByWeightElement']);

    $field_definitions = [];
    foreach ($orderedFields as $key => $settings) {
      // If the field is not excluded
      if (in_array($key,$excluded))
        continue;

      if (array_key_exists($key, $fieldDef)) {
        $definition = $fieldDef[$key];
        // Should be an instance of FieldConfigInterface
        if ($definition instanceof FieldConfigInterface) {
          $field_definitions[] = $definition;
        }
      }
      else {
        drupal_set_message($this->t('Field has no definition (%entityType.%bundle): %field', ['%entityType' => $entity_type_id, '%bundle' => $bundle, '%field' => $key]), 'warning');
      }
    }

    //      $bundle_fields = array_filter($this->entityManager->getFieldDefinitions($entity_type_id, $bundle), function ($field_definition) {
    //        return !$field_definition->isComputed();
    //      });

    //$field_definitions = $this->entityFieldManager->getFieldDefinitions($entityType, $nodeType);

    return $field_definitions;
  }

  /**
   * Generate selected templates for a given entity type
   *
   * @param $baseTemplatePath
   * @param $destDir
   * @param $entityType
   * @param $selected
   * @param $addComment
   * @param $replaceContent
   * @param array $excluded
   *   Fields to exclude
   */
  private function generateEntityTypesTemplates($baseTemplatePath, $destDir, $entityType, $selected, $addComment, $replaceContent, $excluded, $generateViewModes) {

    if (count($selected) < 1) {
      return;
    }

    // Prepare the destination directory
    if (!file_prepare_directory($destDir, FILE_MODIFY_PERMISSIONS | FILE_CREATE_DIRECTORY)) {
      drupal_set_message($this->t('Could not prepare the directory: %dir', ['%dir' => $destDir]), 'error');
      return;
    }

    $nodeContent = file_get_contents($baseTemplatePath);

    // Retrieve indentation from the original file
    if (preg_match('/\n(\s+){{ content }}/', $nodeContent, $matches))
      $indent = $matches[1];
    else
      $indent = "  ";

    //$selected = array_filter($values['node_table']);
    foreach ($selected as $nodeType) {
      // Should we generate view modes ?
      if ($generateViewModes)
        $viewModes = array_keys($this->entityDisplayRepository->getViewModeOptionsByBundle($entityType, $nodeType));
      else
        $viewModes = ['default'];

      // Generate files according to view modes too
      foreach ($viewModes as $viewMode) {
        $viewModeStr = ($viewMode === 'default' )? "" : '--' . strtr($viewMode, '_', '-');
        // Get Field Definitions
        $fieldDefinitions = $this->getDefinitionsFromViewMode($entityType, $nodeType, $viewMode, $excluded);
        // Prepare options
        $options = ['addComment' => $addComment, 'replaceContent' => $replaceContent, 'indent' => $indent];
        // Get fields info
        $fieldsInfo = $this->getFieldsInfo($fieldDefinitions, $options);
        // Generate the new content
        $fileContent = $this->updateContent($nodeContent, $fieldsInfo, $options);

        // Create a file per Entity Type, per Bundle and per View Mode
        file_unmanaged_save_data($fileContent, $destDir . "/" . $entityType . "--" . strtr($nodeType, '_', '-') . $viewModeStr . ".html.twig", FILE_EXISTS_REPLACE);//, "public://toto.txt");
      }
    }

    drupal_set_message($this->t('Templates for "%et" have been created in %dir', ['%et' => $entityType, '%dir' => $destDir]));
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {

    $values = $form_state->getValues();

    // Should generate the comment section ?
    $addComment = !empty($values['add_comment']);
    // Should replace {{ content }} ?
    $replaceContent = !empty($values['replace_content']);
    // Should generate ALL View Modes
    $generateViewModes = !empty($values['view_modes']);
    // Fields to exclude ?
    $excluded = explode(" ", $values['excluded_fields']);

    // Generate templates for each Entity Types
    foreach($this->entityTypeIds as $entityTypeId) {
      $this->generateEntityTypesTemplates(
        $values[$entityTypeId . '_tpl'],
        $values[$entityTypeId . '_dest'],
        $entityTypeId,
        array_filter($values[$entityTypeId . '_table']),
        $addComment,
        $replaceContent,
        $excluded,
        $generateViewModes
      );
    }
  }
}
