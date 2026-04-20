<?php
// app/controllers/LookupController.php

namespace App\Controllers;

use App\Core\BaseController;
use App\Repositories\LookupRepository;

/**
 * LookupController — serves reference data to all UI modules.
 * categories, departments, sites, locations, vendors, users
 */
class LookupController extends BaseController
{
    private LookupRepository $repo;
    private array $allowed = ['categories','departments','sites','locations','vendors','users'];

    public function __construct()
    {
        parent::__construct();
        $this->repo = new LookupRepository();
    }

    // GET /lookup/list?resource=categories
    public function list(): never
    {
        $this->requireMethod('GET');
        $resource = $this->getQuery('resource', '');

        if (!in_array($resource, $this->allowed, true)) {
            $this->error("Unknown resource '{$resource}'. Allowed: " . implode(', ', $this->allowed));
        }

        $extra = [];
        if ($resource === 'locations') $extra['site_id'] = $this->getQuery('site_id');
        if ($resource === 'users')     $extra['search']  = $this->getQuery('search', '');

        $this->success($this->repo->getAll($resource, $extra));
    }

    // GET /lookup/view?resource=categories&id=X
    public function view(): never
    {
        $this->requireMethod('GET');
        $resource = $this->getQuery('resource', '');
        $id       = (int) $this->getQuery('id');

        if (!in_array($resource, $this->allowed, true)) $this->error("Unknown resource.");
        if (!$id) $this->error('ID required.');

        $row = $this->repo->findOne($resource, $id);
        if (!$row) $this->notFound(ucfirst($resource) . ' not found.');
        $this->success($row);
    }

    // POST /lookup/create?resource=X
    public function create(): never
    {
        $this->requireMethod('POST');
        $resource = $this->getQuery('resource', '');
        if (!in_array($resource, $this->allowed, true)) $this->error("Unknown resource.");

        $body = $this->getBody();
        if (empty($body['name'])) $this->error('Name is required.');

        $id = $this->repo->createRecord($resource, $this->filterFields($resource, $body));
        $this->created(['id' => $id], ucfirst($resource) . ' created.');
    }

    // POST /lookup/update?resource=X&id=Y
    public function update(): never
    {
        $this->requireMethod('POST');
        $resource = $this->getQuery('resource', '');
        $id       = (int) $this->getQuery('id');
        if (!in_array($resource, $this->allowed, true)) $this->error("Unknown resource.");
        if (!$id) $this->error('ID required.');

        $body = $this->getBody();
        $this->repo->updateRecord($resource, $id, $this->filterFields($resource, $body));
        $this->success(['id' => $id], ucfirst($resource) . ' updated.');
    }

    // POST /lookup/delete?resource=X&id=Y
    public function delete(): never
    {
        $this->requireMethod('POST');
        $resource = $this->getQuery('resource', '');
        $id       = (int) $this->getQuery('id');
        if (!in_array($resource, $this->allowed, true)) $this->error("Unknown resource.");
        if (!$id) $this->error('ID required.');

        $this->repo->deactivateRecord($resource, $id);
        $this->success(null, ucfirst($resource) . ' deactivated.');
    }

    private function filterFields(string $resource, array $data): array
    {
        $fieldMap = [
            'categories'  => ['name','description'],
            'departments' => ['name','cost_center'],
            'sites'       => ['name','address'],
            'locations'   => ['name','description','site_id'],
            'vendors'     => ['name','contact_name','email','phone','address','sla_notes'],
            'users'       => ['employee_id','full_name','email','phone','department_id','role'],
        ];
        $allowed = $fieldMap[$resource] ?? ['name'];
        $result  = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $result[$col] = $data[$col] === '' ? null : $data[$col];
            }
        }
        return $result;
    }
}
