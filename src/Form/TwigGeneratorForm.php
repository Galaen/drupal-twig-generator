<?php

namespace Drupal\twig_generator\Form;

use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\ContentEntityType;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\File\FileSystemInterface;
/**
 * Class TwigGeneratorForm.
 *
 * @todo Create a Drush command to export using CLI instead of UI
 * @todo Options per Entity Type (comment, replace, view modes)
 * @todo To be able to change the "original" template and specify what to replace (partially done with conf.)
 * @todo Being able to export custom content types (partially done with conf.)
 * @todo Save settings in Conf/State
 * @todo Is there a way to get the default template programmatically ?
 * @todo We might not want to exclude Fields with no definition... (like 'links')
 *
 * @package Drupal\twig_generator\Form
 */
class TwigGeneratorForm extends ConfigFormBase {

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
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'twig_generator.settings',
    ];
  }

  /**
   * Class constructor.
   *
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

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'twig_generator_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    // getEditable if we want to be able to set & save.
    $config = $this->config('twig_generator.settings');

    // GLOBAL SETTINGS
    // ===============.
    $form['add_comment'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Add fields in comment'),
      '#default_value' => $config->get('general.add_comment'),
    ];

    $form['replace_content'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Replace {{ content }} with fields'),
      '#default_value' => $config->get('general.replace_content'),
    ];

    $form['view_modes'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Generate ALL View Modes for each Bundle'),
      '#default_value' => $config->get('general.view_modes'),
    ];

    $form['excluded_fields'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Fields to exclude (space separated machine names)'),
      '#description' => $this->t('Those fields will be excluded from the TWIG.'),
      '#default_value' => $config->get('general.excluded_fields'),
    ];

    // SETTINGS PER ENTITY TYPE
    // ========================.
    // For each Provider get all entities.
    foreach ($this->getAllContentEntitiesByProvider() as $provider => $entities) {

      $provider_conf = $config->get($provider);

      // Fieldset.
      $form[$provider] = [
        '#type' => 'details',
        '#title' => 'Entity provide by ' . $provider,

      ];
      $form[$provider][$provider . '_bool'] = [
        '#type' => 'checkbox',
        '#title' => 'Generate Templates for this provider',
        '#default_value' => ($provider_conf['status']) ? $provider_conf['status'] : 0,
      ];

      // For each entity type get their bundles and list them.
      foreach ($entities as $entityTypeId) {

        $entityType = $this->entityTypeManager->getDefinition($entityTypeId, FALSE);

        if (isset($entityType) && $entityType->entityClassImplements(FieldableEntityInterface::class)) {

          $title = ($entityType->getBundleLabel()) ? $entityType->getBundleLabel() : $entityType->get('id');

          // Fieldset.
          $form[$provider][$entityTypeId] = [
            '#type' => 'details',
            '#title' => $title,
            '#states' => [
              'visible' => [
                ':input[name="' . $provider . '_bool"]' => ['checked' => TRUE],
              ],
            ],
          ];

          if (isset($provider_conf[$entityTypeId]['origin_tpl'])) {
            $tpl_path = $provider_conf[$entityTypeId]['origin_tpl'];
          }
          else {
            $template_probable_path = $this->detectOriginTpl($entityType);
            $tpl_path = ($template_probable_path != '') ? $template_probable_path : "basic_tpl";
          }

          if (isset($provider_conf[$entityTypeId]['desti_tpl'])) {
            $tpl_dest = $provider_conf[$entityTypeId]['desti_tpl'];
          }
          else {
            $tpl_dest = "modules/templates/" . $entityTypeId;
          }

          // TPL directory.
          $form[$provider][$entityTypeId][$entityTypeId . '_tpl'] = [
            '#type' => 'textfield',
            '#title' => $this->t('%type default template', ['%type' => $entityType->getBundleLabel()]),
            '#description' => $this->t('Path of the template to use.'),
            '#default_value' => $tpl_path,
            // '#required' => TRUE,
          ];
          // Destination directory.
          $form[$provider][$entityTypeId][$entityTypeId . '_dest'] = [
            '#type' => 'textfield',
            '#title' => $this->t('%type export directory', ['%type' => $entityType->getBundleLabel()]),
            '#description' => $this->t('The directory where files will be created. Directory will be created if not exists.'),
            '#default_value' => $tpl_dest,
            '#required' => TRUE,
          ];

          $bundleHeader = ['bundle' => t('Bundles')];
          // Initialize an empty array.
          $table = [];
          $defaults = [];
          $bundles = $this->entityTypeBundleInfo->getBundleInfo($entityTypeId);
          foreach (array_keys($bundles) as $bundle) {
            $table[$bundle] = [
              'bundle' => $bundle,
            ];

            // @todo waiting https://www.drupal.org/node/1421132 are released to work
            $defaults[$bundle] = ($provider_conf[$entityTypeId]['bundles'][$bundle] == $bundle) ? TRUE : FALSE;
          }

          $form[$provider][$entityTypeId][$entityTypeId . '_table'] = [
            '#type' => 'tableselect',
            '#header' => $bundleHeader,
            '#options' => $table,
            '#empty' => t('No Bundle found'),
          // To select all by default.
            '#attributes' => ['checked' => TRUE],
            '#default_value' => $defaults,
          ];
        }
      }
    }

    // ACTIONS
    // =======.
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

  /**
   *
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // parent::validateForm($form, $form_state);.
    $values = $form_state->getValues();

    // Check for each item if destination tpl are set.
    foreach ($this->getAllContentEntitiesByProvider() as $provider => $entities) {

      if ($values[$provider . '_bool'] == 0) {
        continue;
      }

      foreach ($entities as $entityTypeId) {
        if (!isset($values[$entityTypeId . '_dest'])) {
          $form_state->setErrorByName($entityTypeId . '_dest', $this->t('You must set a path!'));
        }
      }
    }
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
    $comment = $lineStart . '- ' . $definition->getName() . ":" . "\n";
    $comment .= $lineStart . "  | type: " . $definition->getType() . "\n";
    $comment .= $lineStart . "  | cardinality: " . $definition->getFieldStorageDefinition()->getCardinality() . "\n";
    if ($definition->isRequired()) {
      $comment .= $lineStart . "  | required" . "\n";
    }
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

      // Add Field to comment block if needed.
      if (!empty($options['addComment'])) {
        $comment .= $this->generateFieldComment($definition);
      }

      if (!empty($options['replaceContent'])) {
        $fields .= ("\n" . $indent . "{{ content." . $definition->getName() . " }}" . " {# " . $definition->getType() . '(max: ' . $definition->getFieldStorageDefinition()->getCardinality() . ')' . " #}");
      }
    }

    return [
      'comment' => $comment,
      'fields' => $fields,
    ];
  }

  /**
   *
   */
  private function updateContent($original_content, $fields_info, $options) {

    $content = $original_content;
    // Replace {{ content }} with fields if needed.
    if (!empty($options['replaceContent'])) {
      $content = preg_replace('/\n\s+{{ content }}/', $fields_info['fields'], $content);
    }
    // Add Comment to describe fields.
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
    // Get Fields definition.
    $fieldDef = $viewDisp->get('fieldDefinitions');
    // Get fields not hidden.
    $orderedFields = $viewDisp->get('content');
    // Ordered by Weight.
    uasort($orderedFields, ['Drupal\Component\Utility\SortArray', 'sortByWeightElement']);

    $field_definitions = [];
    foreach ($orderedFields as $key => $settings) {
      // If the field is not excluded.
      if (in_array($key, $excluded)) {
        continue;
      }

      if (array_key_exists($key, $fieldDef)) {
        $definition = $fieldDef[$key];
        // Should be an instance of FieldConfigInterface.
        if ($definition instanceof FieldConfigInterface) {
          $field_definitions[] = $definition;
        }
      }
      else {
        \Drupal::messenger()->addMessage($this->t('Field has no definition (%entityType.%bundle): %field', ['%entityType' => $entity_type_id, '%bundle' => $bundle, '%field' => $key]), 'warning');
      }
    }

    // $bundle_fields = array_filter($this->entityManager->getFieldDefinitions($entity_type_id, $bundle), function ($field_definition) {
    //        return !$field_definition->isComputed();
    //      });
    // $field_definitions = $this->entityFieldManager->getFieldDefinitions($entityType, $nodeType);
    return $field_definitions;
  }

  /**
   * Generate selected templates for a given entity type.
   *
   * @param $baseTemplatePath
   * @param $destDir
   * @param $entityType
   * @param $selected
   * @param $addComment
   * @param $replaceContent
   * @param array $excluded
   *   Fields to exclude.
   */
  private function generateEntityTypesTemplates($baseTemplatePath, $destDir, $entityType, $selected, $addComment, $replaceContent, $excluded, $generateViewModes) {

    if (count($selected) < 1) {
      return;
    }

    $bool = \Drupal::service('file_system')->prepareDirectory($destDir, FileSystemInterface::MODIFY_PERMISSIONS | FileSystemInterface::CREATE_DIRECTORY);
    // Prepare the destination directory.
    if (!$bool) {
      \Drupal::messenger()->addMessage($this->t('Could not prepare the directory: %dir', ['%dir' => $destDir]), 'error');
      return;
    }

    // @todo Manage empty source TPL
    $nodeContent = file_get_contents($baseTemplatePath);

    // Retrieve indentation from the original file.
    if (preg_match('/\n(\s+){{ content }}/', $nodeContent, $matches)) {
      $indent = $matches[1];
    }
    else {
      $indent = "  ";
    }

    // $selected = array_filter($values['node_table']);
    foreach ($selected as $nodeType) {
      // Should we generate view modes ?
      if ($generateViewModes) {
        $viewModes = array_keys($this->entityDisplayRepository->getViewModeOptionsByBundle($entityType, $nodeType));
      }

      else {
        $viewModes = ['default'];
      }

      // Generate files according to view modes too.
      foreach ($viewModes as $viewMode) {
        $viewModeStr = ($viewMode === 'default') ? "" : '--' . strtr($viewMode, '_', '-');
        // Get Field Definitions.
        $fieldDefinitions = $this->getDefinitionsFromViewMode($entityType, $nodeType, $viewMode, $excluded);
        // Prepare options.
        $options = ['addComment' => $addComment, 'replaceContent' => $replaceContent, 'indent' => $indent];
        // Get fields info.
        $fieldsInfo = $this->getFieldsInfo($fieldDefinitions, $options);
        // Generate the new content.
        $fileContent = $this->updateContent($nodeContent, $fieldsInfo, $options);

        // Create a file per Entity Type, per Bundle and per View Mode.
        // , "public://toto.txt");.
        \Drupal::service('file_system')->saveData($fileContent, $destDir . "/" . $entityType . "--" . strtr($nodeType, '_', '-') . $viewModeStr . ".html.twig", FILE_EXISTS_REPLACE);
      }
    }

    \Drupal::messenger()->addMessage($this->t('Templates for "%et" have been created in %dir', ['%et' => $entityType, '%dir' => $destDir]));
  }

  /**
   *
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $values = $form_state->getValues();
    $conf = $this->config('twig_generator.settings');

    // Should generate the comment section ?
    $conf->set('general.add_comment', $values['add_comment']);
    $addComment = !empty($values['add_comment']);

    // Should replace {{ content }} ?
    $conf->set('general.replace_content', $values['replace_content']);
    $replaceContent = !empty($values['replace_content']);

    // Should generate ALL View Modes.
    $conf->set('general.view_modes', $values['view_modes']);
    $generateViewModes = !empty($values['view_modes']);

    // Fields to exclude ?
    $conf->set('general.excluded_fields', $values['excluded_fields']);
    $excluded = explode(" ", $values['excluded_fields']);

    // Generate templates for each Entity Types.
    foreach ($this->getAllContentEntitiesByProvider() as $provider => $entities) {

      $conf->set($provider . '.status', $values[$provider . '_bool']);
      if ($values[$provider . '_bool'] == 0) {
        continue;
      }

      foreach ($entities as $entityTypeId) {

        $data = [];
        $data['origin_tpl'] = $values[$entityTypeId . '_tpl'];
        $data['desti_tpl'] = $values[$entityTypeId . '_dest'];
        $data['bundles'] = $values[$entityTypeId . '_table'];
        $conf->set($provider . '.' . $entityTypeId, $data);

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

    $conf->save();

  }

  /**
   * Try to find a template.
   */
  private function detectOriginTpl($entityType) {
    $module_handler = \Drupal::service('module_handler');
    $provider = $module_handler->getModule($entityType->getProvider());
    $provider_path = $provider->getPath();

    // 1 - check if provider provide tpl
    $basic_path = $this->checkTplPath($provider_path, $entityType->get('id'));
    if ($basic_path != NULL) {
      return $basic_path;
    }

    // 2 - Tpl exist with bsae_table prefix ( like file)
    $basic_path = $this->checkTplPath($provider_path, $entityType->get('base_table') . '-' . $entityType->get('id'));
    if ($basic_path != NULL) {
      return $basic_path;
    }
  }

  /**
   * Check if a template exist.
   */
  private function checkTplPath($module_path, $tpl_id) {
    // Check nativ id.
    $template_probable_path = $module_path . '/templates/' . $tpl_id . '.html.twig';
    if (file_exists($template_probable_path)) {
      return $template_probable_path;
    }

    // Change "_" by "-".
    $template_probable_path = $module_path . '/templates/' . str_replace('_', '-', $tpl_id) . '.html.twig';
    if (file_exists($template_probable_path)) {
      return $template_probable_path;
    }

    return NULL;
  }

  /**
   * Select all Content entity with view modes.
   */
  private function getAllContentEntitiesByProvider() {
    // Get all Content Entities.
    $content_entity_types = [];

    $entity_type_definations = \Drupal::entityTypeManager()->getDefinitions();
    /** @var EntityTypeInterface $definition */
    foreach ($entity_type_definations as $definition) {
      if ($definition instanceof ContentEntityType) {
        $entity_id = $definition->get('id');
        $bundles = $this->entityTypeBundleInfo->getBundleInfo($entity_id);
        $view_modes_count = 0;
        foreach (array_keys($bundles) as $bundle) {
          $view_modes_count += count(array_keys($this->entityDisplayRepository->getViewModeOptionsByBundle($entity_id, $bundle)));
        }

        if ($view_modes_count > 0) {
          $content_entity_types[$definition->get('provider')][] = $entity_id;
        }
      }
    }
    return $content_entity_types;
  }

}
