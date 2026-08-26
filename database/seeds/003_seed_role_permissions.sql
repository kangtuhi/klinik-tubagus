INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.slug = 'owner'
ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN (
    'users.view', 'users.create', 'users.update', 'users.delete',
    'patients.view', 'patients.create', 'patients.update', 'patients.delete',
    'doctors.view', 'doctors.create', 'doctors.update', 'doctors.delete',
    'medical_records.view',
    'medicines.view',
    'queue.view', 'queue.create', 'queue.update',
    'billing.view', 'billing.create', 'billing.update',
    'reports.view'
)
WHERE r.slug = 'administrator'
ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN (
    'patients.view',
    'medical_records.view', 'medical_records.create', 'medical_records.update',
    'queue.view'
)
WHERE r.slug = 'doctor'
ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN (
    'patients.view',
    'medical_records.view',
    'queue.view', 'queue.update'
)
WHERE r.slug = 'nurse'
ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN (
    'patients.view',
    'medicines.view', 'medicines.create', 'medicines.update'
)
WHERE r.slug = 'pharmacy'
ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN (
    'patients.view',
    'billing.view', 'billing.create', 'billing.update'
)
WHERE r.slug = 'cashier'
ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN (
    'patients.view', 'patients.create', 'patients.update',
    'queue.view', 'queue.create', 'queue.update'
)
WHERE r.slug = 'registration'
ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);
