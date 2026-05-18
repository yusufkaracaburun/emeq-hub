# Deferred Items — Phase 11

Out-of-scope discoveries during Phase 11 execution. Not blocking for this phase; logged for follow-up.

## From Plan 11-02 — Hub-side composer update

### Pre-existing UserResource test failure (unrelated to Saloon v4)

- **Test:** `Tests\Feature\Admin\UserResourceTest::test_super_admin_can_create_user_via_resource`
- **File:** `tests/Feature/Admin/UserResourceTest.php:48`
- **Failure:** `Component has errors: "data.roles" — Failed asserting that false is true.`
- **Root cause:** `UserForm::configure()` (`app/Filament/Resources/Users/Schemas/UserForm.php:44-49`) marks the `roles` Select as `required` on `operation === 'create'`, but the test's `fillForm()` call (test line 53-57) does not provide a `roles` value. Server-side validation rejects submit; `assertHasNoFormErrors()` fails.
- **Out-of-scope evidence:** `composer.lock` diff for this plan shows only `emeq/snelstart-api` changed (`dev-master` → `v0.2.0`, commit `e9076d4` → `ce7c66c`). No Filament, Livewire, Spatie laravel-permission, or Hub-side code changes. The UserResource form-schema has been in this state since commit `4a9c54e` (Plan 09-10) and has not been touched in Phase 11.
- **Disposition:** Not addressed in Plan 11-02 per SCOPE BOUNDARY (auto-fix only issues directly caused by the current task's changes). Defer to a follow-up plan or quick-task that either:
  - Updates the test to `fillForm([..., 'roles' => ['super-admin']])` so it exercises the form schema as designed, or
  - Documents in the test why the assertion was originally green if Filament v4's Select-required-on-create validator changed semantics between minor versions.
- **Impact on Plan 11-02 acceptance:** Snelstart-subset suite is 51/51 green (Api/V1/Snelstart + webhook + pass-through + administratie tests). 523/524 Hub-suite green; failing test is in a path Plan 11-02 does not touch (`/admin/users`, no SDK call, no OData, no webhook).
