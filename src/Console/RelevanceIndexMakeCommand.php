<?php

declare(strict_types=1);

namespace Relevance\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RelevanceIndexMakeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:relevance-index {name : The name of the index class}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new Relevance IndexDefinition class';

    public function handle(): int
    {
        $rawName = $this->argument('name');
        $name = is_string($rawName) ? $rawName : '';

        if (!str_ends_with($name, 'Index')) {
            $name .= 'Index';
        }

        $className = Str::studly(class_basename($name));
        $indexName = Str::snake(Str::replaceLast('Index', '', $className));

        $path = app_path("Search/{$className}.php");

        if (file_exists($path)) {
            $this->error("Index definition [{$path}] already exists!");
            return self::FAILURE;
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $template = <<<PHP
<?php

declare(strict_types=1);

namespace App\Search;

use Illuminate\Database\Eloquent\Builder;
use Relevance\Schema\IndexDefinition;
use Relevance\Schema\Schema;

class {$className} extends IndexDefinition
{
    public function name(): string
    {
        return '{$indexName}';
    }

    /**
     * Source query used by `relevance:index:rebuild`. Return your Eloquent query,
     * e.g. `return \\App\\Models\\Product::query();`
     */
    public function source(): ?Builder
    {
        return null;
    }

    public function schema(Schema \$s): void
    {
        \$s->id('id');
        \$s->text('title')->weight(2.0);
        \$s->text('body');
        \$s->keyword('category')->filterable()->facet();
        \$s->number('price')->filterable()->sortable();
        \$s->bool('in_stock')->filterable();
        \$s->vector('embedding')->from(['title', 'body']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDocument(mixed \$item): array
    {
        return [
            'id'       => (string) \$item->getKey(),
            'title'    => \$item->title,
            'body'     => \$item->body,
            'category' => \$item->category,
            'price'    => (float) \$item->price,
            'in_stock' => (bool) \$item->in_stock,
        ];
    }
}

PHP;

        file_put_contents($path, $template);

        $this->info("Relevance index definition [{$className}] created successfully at [{$path}].");
        $this->line("Remember to register it in <comment>config/relevance.php</comment> under 'indexes'.");

        return self::SUCCESS;
    }
}
