<?php

namespace App\Modules\Support;

use Illuminate\Database\Eloquent\Model;

interface ProductModule
{
    public function key(): string;

    public function name(): string;

    /**
     * Resource name => model class, label, optional eager-loads and searchable columns.
     *
     * @return array<string, array{
     *     model: class-string<Model>,
     *     label: string,
     *     with?: list<string>,
     *     searchable?: list<string>
     * }>
     */
    public function resources(): array;

    /**
     * Sidebar-shaped endpoints. Empty when the product only has generic resources.
     *
     * @return array<string, array{
     *     label: string,
     *     requires_admin?: bool,
     *     resources: array<string, array{
     *         label: string,
     *         path: string,
     *         requires_admin?: bool,
     *         operations?: list<array{method: string, path: string, label: string, auth?: bool, body?: array<string, mixed>}>
     *     }>
     * }>
     */
    public function sections(): array;

    /**
     * Login and session routes for the playground. Empty when the product has no auth yet.
     *
     * @return list<array{method: string, path: string, label: string, auth?: bool, body?: array<string, mixed>}>
     */
    public function authOperations(): array;

    /**
     * Health and other product-level checks. Health is always added by the catalog.
     *
     * @return list<array{method: string, path: string, label: string, auth?: bool}>
     */
    public function utilities(): array;
}
