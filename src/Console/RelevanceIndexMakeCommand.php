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

use Relevance\Indexing\IndexDefinition;
use Relevance\Schema\IndexSchemaBuilder;

class {$className} extends IndexDefinition
{
    public function name(): string
    {
        return '{$indexName}';
    }

    public function schema(IndexSchemaBuilder \$schema): void
    {
        \$schema->string('title')->searchable()->weight(2.0);
        \$schema->text('body')->searchable();
        \$schema->keyword('category')->filterable()->facet();
        \$schema->float('price')->filterable()->sortable();
        \$schema->boolean('in_stock')->filterable();
        \$schema->vector('embedding', dimensions: 1536)->similarity('cosine');
    }
}

PHP;

        file_put_contents($path, $template);

        $this->info("Relevance index definition [{$className}] created successfully at [{$path}].");
        $this->line("Remember to register it in <comment>config/relevance.php</comment> under 'indexes'.");

        return self::SUCCESS;
    }
}
