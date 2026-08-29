<?php

namespace App\Modules\Support;

interface ProductModule
{
    public function key(): string;

    public function name(): string;

    /**
     * Resource name => model class, label, optional eager-loads and searchable columns.
     *
     * @return array<string, array{
     *     model: class-string<\Illuminate\Database\Eloquent\Model>,
     *     label: string,
     *     with?: list<string>,
     *     searchable?: list<string>
     * }>
     */
    public function resources(): array;
}
