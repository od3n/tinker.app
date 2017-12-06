<?php

namespace App\Console\Commands;

use Docker\API\V1_32\Model\ContainersCreatePostBody;
use Docker\Docker;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Stream;
use Illuminate\Console\Command;

class DockerStartContainer extends Command
{
    protected $signature = 'docker:start-container {--W|websockets}';
    
    protected $description = 'Start new Docker container';
    
    public function handle()
    {
        $docker = Docker::create(Docker::VERSION_1_32);
        
        $containerName = 'tinker-'.str_random();

        $containerCreatePostBody = new ContainersCreatePostBody();
        $containerCreatePostBody->setImage('alexvanderbist/tinker-sh-image');
        // $containerCreatePostBody->setCmd(['/usr/bin/php', '/var/www/artisan', 'tinker']); // is in image now
        $containerCreatePostBody->setTty(true);
        $containerCreatePostBody->setOpenStdin(true); // -i interactive flag = keep stdin open even when not attached
        // $containerCreatePostBody->setStdinOnce(true); // close stdin after client dc
        $containerCreatePostBody->setAttachStdin(true);
        $containerCreatePostBody->setAttachStdout(true);
        $containerCreatePostBody->setAttachStderr(true);

        $docker->container()->containerCreate($containerCreatePostBody, ['name' => $containerName]);
        
        $this->comment($containerName);
        
        // start container
        $docker->container()->containerStart($containerName);

        if ($this->option('websockets')) {
            return $this->listenToWebsockets($docker, $containerName);
        }

        $this->listenToHijackedRequest($docker, $containerName);
    }

    protected function listenToHijackedRequest($docker, string $containerName)
    {
        // Attach endpoint works => but not with TTY (docker-php parses frames wrong)
        $attachStream = $docker->getContainerManager()->attach($containerName, [
            'stream' => true,
            'stdin' => true,
            'stdout' => true,
            'stderr' => true
        ]);
        
        $attachStream->onStdout(function ($stdout) {
            echo $stdout;
        });

        $attachStream->onStderr(function ($stderr) {
            echo $stderr;
        });

        $attachStream->wait();
    }

    protected function listenToWebsockets($docker, string $containerName)
    {
        // Websocket API doesnt (on mac -> see gh issue)
        $response = $docker->container()->containerAttach($containerName, [
            'stream' => true,
            'stdout' => true,
            'stderr' => true,
            'stdin'  => true,
        ], false);

        /** @var \Http\Client\Socket\Stream */
        $stream = Psr7\stream_for($response->getBody());

        dump($stream->isWritable());
        
        while (true) {
            // fwrite($stream->socket, 'ejejeje');
            echo $response->getBody()->read(8);
        }
    }
}
