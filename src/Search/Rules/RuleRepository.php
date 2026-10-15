<?php

declare(strict_types=1);

namespace Relevance\Search\Rules;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RuleRepository
{
    /** @var list<Rule> */
    private array $inMemoryRules = [];

    public function addRule(Rule $rule): self
    {
        $this->inMemoryRules[] = $rule;
        return $this;
    }

    /**
     * Fluent helper: pin IDs at positions
     *
     * @param list<string|int> $ids
     * @param list<int> $positions
     */
    public function pin(string $query, array $ids, array $positions = [], string $index = 'default', int $priority = 100): self
    {
        $strIds = array_map('strval', $ids);
        if (empty($positions)) {
            $positions = range(1, count($strIds));
        }

        $rule = new Rule(
            name: "pin_{$query}",
            index: $index,
            priority: $priority,
            matcher: new ExactMatcher($query),
            actions: [
                [
                    'type' => 'pin',
                    'ids' => $strIds,
                    'positions' => $positions,
                ],
            ],
        );

        $this->addRule($rule);

        $this->persistRuleIfTableExists($rule);

        return $this;
    }

    /**
     * Fluent helper: bury IDs
     *
     * @param list<string|int> $ids
     */
    public function bury(string $query, array $ids, string $index = 'default', int $priority = 100): self
    {
        $rule = new Rule(
            name: "bury_{$query}",
            index: $index,
            priority: $priority,
            matcher: new ExactMatcher($query),
            actions: [
                [
                    'type' => 'bury',
                    'ids' => array_map('strval', $ids),
                ],
            ],
        );

        $this->addRule($rule);
        $this->persistRuleIfTableExists($rule);

        return $this;
    }

    /**
     * Fluent helper: exclude IDs
     *
     * @param list<string|int> $ids
     */
    public function exclude(string $query, array $ids, string $index = 'default', int $priority = 100): self
    {
        $rule = new Rule(
            name: "exclude_{$query}",
            index: $index,
            priority: $priority,
            matcher: new ExactMatcher($query),
            actions: [
                [
                    'type' => 'exclude',
                    'ids' => array_map('strval', $ids),
                ],
            ],
        );

        $this->addRule($rule);
        $this->persistRuleIfTableExists($rule);

        return $this;
    }

    /**
     * Fluent helper: redirect navigational queries
     */
    public function redirect(string $query, string $url, string $index = 'default', int $priority = 100): self
    {
        $rule = new Rule(
            name: "redirect_{$query}",
            index: $index,
            priority: $priority,
            matcher: new ExactMatcher($query),
            actions: [
                [
                    'type' => 'redirect',
                    'url' => $url,
                ],
            ],
        );

        $this->addRule($rule);
        $this->persistRuleIfTableExists($rule);

        return $this;
    }

    /**
     * @return list<Rule>
     */
    public function getRulesForIndex(string $index): array
    {
        $rules = [];

        // 1. In-memory rules
        foreach ($this->inMemoryRules as $rule) {
            if ($rule->index === $index || $rule->index === '*') {
                $rules[] = $rule;
            }
        }

        // 2. Database rules if table exists
        try {
            if (Schema::hasTable('relevance_rules')) {
                $rows = DB::table('relevance_rules')
                    ->where(function ($query) use ($index) {
                        $query->where('index', $index)->orWhere('index', '*');
                    })
                    ->where('enabled', true)
                    ->orderByDesc('priority')
                    ->get();

                foreach ($rows as $row) {
                    $matcher = match ($row->matcher_type) {
                        'contains' => new ContainsMatcher((string) $row->matcher_value),
                        'regex' => new RegexMatcher((string) $row->matcher_value),
                        default => new ExactMatcher((string) $row->matcher_value),
                    };

                    /** @var array<int, array<string, mixed>> $actions */
                    $actions = is_string($row->actions) ? json_decode($row->actions, true) : (array) $row->actions;

                    $rules[] = new Rule(
                        name: (string) $row->name,
                        index: (string) $row->index,
                        priority: (int) $row->priority,
                        matcher: $matcher,
                        actions: $actions,
                        enabled: (bool) $row->enabled,
                        startAt: $row->start_at ? (string) $row->start_at : null,
                        endAt: $row->end_at ? (string) $row->end_at : null,
                    );
                }
            }
        } catch (\Throwable) {
            // DB not available
        }

        // Sort combined rules by priority DESC
        usort($rules, static fn (Rule $a, Rule $b) => $b->priority <=> $a->priority);

        return $rules;
    }

    private function persistRuleIfTableExists(Rule $rule): void
    {
        try {
            if (Schema::hasTable('relevance_rules')) {
                DB::table('relevance_rules')->updateOrInsert(
                    ['name' => $rule->name, 'index' => $rule->index],
                    [
                        'priority' => $rule->priority,
                        'matcher_type' => 'exact',
                        'matcher_value' => $rule->name,
                        'actions' => json_encode($rule->actions),
                        'enabled' => $rule->enabled,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        } catch (\Throwable) {
            // Ignore if DB not ready
        }
    }
}
