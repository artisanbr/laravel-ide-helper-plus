<?php

namespace ArtisanLabs\LaravelIdeHelperPlus\Console;

use Illuminate\Console\Command;

class IdeHelperCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'idehelper';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate All IDE Helper';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->call('config:clear');
        $this->call('ide-helper:generate');
        $this->call('ide-helper:meta');
        $this->call('ide-helper:models', ['--nowrite' => 1]);
        $this->call('cache:clear');
    }
}
