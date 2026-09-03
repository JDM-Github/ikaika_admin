<?php

namespace App\Support\Workspace;

/**
 * The relationship graph of one product database, as fields a grid can render.
 *
 * Three shapes reach the same place. A junction is an Airtable link field wearing a
 * table costume and becomes chips on both parents. A child table holding one link
 * back is a multipleSelects field converted to rows and becomes chips on the parent
 * plus a single chip on the child. A plain foreign key becomes that single chip
 * alone. Without this the grid prints an integer where the base showed a record,
 * which is the whole reason the base was relational.
 */
final class WorkspaceLinks
{
    public const JUNCTION = 'junction';

    public const CHILD = 'child';

    public const PARENT = 'parent';

    /**
     * Qualifier values listed before a junction stops splitting into one field each.
     */
    private const MAX_VARIANTS = 8;

    public function __construct(private readonly WorkspaceIntrospector $introspector) {}

    /**
     * The chip columns of one table: many-to-many and one-to-many. A foreign key is
     * not here -- it occupies a real column, so parentColumns upgrades that column in
     * place instead of adding a second one beside it.
     *
     * @return list<array<string, mixed>>
     */
    public function fields(string $product, string $table): array
    {
        return $this->disambiguated(array_merge(
            $this->junctionFields($product, $table),
            $this->childFields($product, $table),
        ));
    }

    /**
     * Foreign keys of this table keyed by the local column they occupy, so the grid can
     * upgrade a scalar integer in place and let the column stay sortable and filterable.
     *
     * @return array<string, array<string, mixed>>
     */
    public function parentColumns(string $product, string $table): array
    {
        $keyed = [];
        foreach ($this->parentFields($product, $table) as $field) {
            $keyed[(string) $field['name']] = $field;
        }

        return $keyed;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function junctionFields(string $product, string $table): array
    {
        $labels = (array) config('workspace.link_labels', []);
        $fields = [];

        foreach ($this->introspector->junctions($product) as $junction => $shape) {
            foreach ([['left', 'right'], ['right', 'left']] as [$near, $far]) {
                if ($shape[$near] !== $table) {
                    continue;
                }

                $target = $shape[$far];
                $variants = [null];
                if ($shape['qualifier'] !== null) {
                    $found = $this->introspector->options($product, $junction, $shape['qualifier'], self::MAX_VARIANTS);
                    $variants = $found === [] ? [null] : $found;
                }

                foreach ($variants as $variant) {
                    $name = $variant === null ? 'link__'.$target : 'link__'.$target.'__'.$variant;
                    $label = $variant === null
                        ? WorkspaceFieldType::label($target)
                        : (string) ($labels[$variant] ?? WorkspaceFieldType::label($variant));

                    $fields[] = $this->descriptor([
                        'name' => $name,
                        'label' => $label,
                        'hint' => $this->linkHint($junction, $target, (string) $shape[$far.'_column']),
                        'type' => WorkspaceFieldType::LINK,
                        'kind' => self::JUNCTION,
                        'target' => $target,
                        'via' => $junction,
                        'near_column' => $shape[$near.'_column'],
                        'far_column' => $shape[$far.'_column'],
                        'label_column' => null,
                        'qualifier' => $shape['qualifier'],
                        'variant' => $variant,
                    ]);
                }
            }
        }

        return $fields;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function childFields(string $product, string $table): array
    {
        $fields = [];

        foreach ($this->introspector->childLinks($product) as $child => $shape) {
            if ($shape['parent'] !== $table) {
                continue;
            }

            $fields[] = $this->descriptor([
                'name' => 'link__'.$child,
                'label' => $this->childLabel($table, $child),
                'hint' => $shape['column'],
                'type' => WorkspaceFieldType::LINK,
                'kind' => self::CHILD,
                'target' => $child,
                'via' => null,
                'near_column' => null,
                'far_column' => $shape['column'],
                'label_column' => $shape['label_column'],
                'qualifier' => null,
                'variant' => null,
            ]);
        }

        return $fields;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parentFields(string $product, string $table): array
    {
        $shape = $this->introspector->childLinks($product)[$table] ?? null;
        if ($shape === null) {
            return [];
        }

        $parent = $shape['parent'];
        $key = $this->introspector->surrogateKey($product, $parent);
        if ($key === null) {
            return [];
        }

        return [$this->descriptor([
            'name' => $shape['column'],
            'label' => WorkspaceFieldType::label($parent),
            'hint' => $parent,
            'type' => WorkspaceFieldType::REFERENCE,
            'kind' => self::PARENT,
            'target' => $parent,
            'via' => null,
            'near_column' => $shape['column'],
            'far_column' => $key,
            'label_column' => null,
            'qualifier' => null,
            'variant' => null,
        ])];
    }

    /**
     * A reference is a real column, so it sorts and filters like one; the chips of a
     * junction or a child live in no column and can do neither.
     *
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function descriptor(array $field): array
    {
        $isReference = $field['type'] === WorkspaceFieldType::REFERENCE;

        return $field + [
            'width' => WorkspaceFieldType::width((string) $field['type']),
            'nullable' => true,
            'sortable' => $isReference,
            'searchable' => false,
            'numeric' => false,
            'filled' => null,
            'editable' => false,
        ];
    }

    /**
     * bim_form_elements_included on bim_form reads as Elements Included, not as the
     * parent's own name repeated back at it.
     */
    private function childLabel(string $parent, string $child): string
    {
        $stem = str_starts_with($child, $parent.'_') ? substr($child, strlen($parent) + 1) : $child;

        return WorkspaceFieldType::label($stem);
    }

    /**
     * Two junctions can reach the same table from one side, and a self-join reaches it
     * twice through a single junction -- employees links to employees as both manager
     * and report. A bare link__employees would collide, so colliding names take the
     * relationship as a suffix and the rest keep the clean name.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private function disambiguated(array $fields): array
    {
        $seen = [];
        foreach ($fields as $field) {
            $seen[(string) $field['name']] = ($seen[(string) $field['name']] ?? 0) + 1;
        }

        $resolved = [];
        foreach ($fields as $field) {
            $name = (string) $field['name'];
            if (($seen[$name] ?? 0) > 1) {
                $field['name'] = $name.'__'.$field['hint'];
                $field['label'] = $field['label'].' ('.WorkspaceFieldType::label((string) $field['hint']).')';
            }

            unset($field['hint']);
            $resolved[] = $field;
        }

        return $resolved;
    }

    /**
     * What tells two links to the same table apart: the column pointed at for a
     * self-join, the junction otherwise.
     */
    private function linkHint(string $junction, string $target, string $farColumn): string
    {
        $stem = str_ends_with($farColumn, '_id') ? substr($farColumn, 0, -3) : $farColumn;

        return $stem === rtrim($target, 's') ? $junction : $stem;
    }
}
