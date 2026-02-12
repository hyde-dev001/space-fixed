<?php

namespace App\Traits\HR;

use App\Models\HR\AuditLog;
use Illuminate\Database\Eloquent\Model;

trait LogsHRActivity
{
    /**
     * Log entity creation
     */
    protected function auditCreated(string $module, Model $entity, ?string $description = null, array $tags = []): void
    {
        $description = $description ?? ucfirst($module) . " created: " . $this->getEntityDescription($entity);
        
        AuditLog::logCreated($module, $entity, $description, $tags);
    }

    /**
     * Log entity update
     */
    protected function auditUpdated(string $module, Model $entity, array $oldValues, ?string $description = null, array $tags = []): void
    {
        $description = $description ?? ucfirst($module) . " updated: " . $this->getEntityDescription($entity);
        
        AuditLog::logUpdated($module, $entity, $oldValues, $description, $tags);
    }

    /**
     * Log entity deletion
     */
    protected function auditDeleted(string $module, Model $entity, ?string $description = null, array $tags = []): void
    {
        $description = $description ?? ucfirst($module) . " deleted: " . $this->getEntityDescription($entity);
        
        AuditLog::logDeleted($module, $entity, $description, $tags);
    }

    /**
     * Log approval action
     */
    protected function auditApproved(string $module, Model $entity, ?string $description = null, array $tags = []): void
    {
        $description = $description ?? ucfirst($module) . " approved: " . $this->getEntityDescription($entity);
        
        AuditLog::logApproved($module, $entity, $description, $tags);
    }

    /**
     * Log rejection action
     */
    protected function auditRejected(string $module, Model $entity, string $reason, array $tags = []): void
    {
        AuditLog::logRejected($module, $entity, $reason, $tags);
    }

    /**
     * Log sensitive data access
     */
    protected function auditSensitiveAccess(string $module, string $entityType, int $entityId, string $description): void
    {
        AuditLog::logSensitiveAccess($module, $entityType, $entityId, $description);
    }

    /**
     * Log custom action
     */
    protected function auditCustom(string $module, string $action, string $description, array $data = []): void
    {
        AuditLog::createLog(array_merge([
            'module' => $module,
            'action' => $action,
            'description' => $description,
            'severity' => $data['severity'] ?? AuditLog::SEVERITY_INFO,
            'tags' => $data['tags'] ?? [],
        ], $data));
    }

    /**
     * Get human-readable description from entity
     */
    private function getEntityDescription(Model $entity): string
    {
        // Try common name patterns
        if (isset($entity->name)) {
            return $entity->name;
        }
        
        if (isset($entity->first_name) && isset($entity->last_name)) {
            return "{$entity->first_name} {$entity->last_name}";
        }
        
        if (isset($entity->document_name)) {
            return $entity->document_name;
        }
        
        if (isset($entity->title)) {
            return $entity->title;
        }
        
        // Fallback to ID
        return "ID {$entity->id}";
    }
}
