<?php

namespace Drupal\operations_cider\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Applies Resource Group defaults to a Resource as in-memory fallbacks.
 *
 * For each inheritable field, if the Resource's value is empty and the parent
 * Resource Group has a value, the Group's value is copied onto the Resource
 * in memory only — never saved. Compute-related fields (jobs info, queue
 * specs, XDMoD resource ID) are intentionally excluded.
 */
class ResourceGroupInheritanceService {

  /**
   * Fields a Resource inherits from its parent Resource Group when empty.
   */
  public const INHERITABLE_FIELDS = [
    'field_rp_login_text',
    'field_rp_ondemand_url',
    'field_rp_mfa_required',
    'field_rp_account_required',
    'field_rp_account_setup_url',
    'field_rp_ssh_login_nodes',
    'field_rp_login_help_links',
    'field_rp_file_transfer_text',
    'field_rp_file_transfer',
    'field_rp_storage_text',
    'field_rp_storage',
    'field_rp_external_storage',
    'field_rp_software_list_url',
    'field_rp_datasets_text',
    'field_rp_datasets',
    'field_rp_support_links',
    'field_rp_office_hours',
  ];

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Apply Group defaults to a Resource's in-memory field values.
   *
   * Safe to call multiple times on the same node: once a field is populated
   * (whether originally or by this method), subsequent calls are a no-op for
   * that field.
   *
   * Callers that reuse the passed entity elsewhere in the same request should
   * operate on a clone, since this method mutates the entity and Drupal's
   * static entity cache would otherwise return the inherited values.
   *
   * @param \Drupal\node\NodeInterface $resource
   *   The Resource node. Must be the access_active_resources_from_cid bundle.
   *
   * @return \Drupal\node\NodeInterface
   *   The same node, mutated in memory. If the Resource has no parent Group,
   *   or none of the Group's inheritable fields are populated, it is returned
   *   unchanged.
   */
  public function applyInheritance(NodeInterface $resource): NodeInterface {
    $group = $this->findParentGroup($resource);
    if (!$group) {
      return $resource;
    }

    foreach (self::INHERITABLE_FIELDS as $field_name) {
      if (!$resource->hasField($field_name) || !$group->hasField($field_name)) {
        continue;
      }
      if (!$resource->get($field_name)->isEmpty()) {
        continue;
      }
      if ($group->get($field_name)->isEmpty()) {
        continue;
      }
      $resource->set($field_name, $group->get($field_name)->getValue());
    }

    return $resource;
  }

  /**
   * Find the parent Resource Group for a Resource, if any.
   *
   * @param \Drupal\node\NodeInterface $resource
   *   The Resource node.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The parent Resource Group, or NULL if the Resource is not a member of
   *   any Group.
   */
  public function findParentGroup(NodeInterface $resource): ?NodeInterface {
    $groups = $this->entityTypeManager->getStorage('node')->loadByProperties([
      'type' => 'resource_group',
      'field_cider_resources' => $resource->id(),
    ]);
    if (!$groups) {
      return NULL;
    }
    if (count($groups) > 1) {
      \Drupal::logger('operations_cider')->warning(
        'Resource @nid is a member of @count Resource Groups; using @group for inheritance.',
        [
          '@nid' => $resource->id(),
          '@count' => count($groups),
          '@group' => reset($groups)->getTitle(),
        ]
      );
    }
    return reset($groups);
  }

}
