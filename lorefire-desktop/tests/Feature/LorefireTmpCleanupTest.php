<?php

namespace Tests\Feature;

use App\Support\AppTemp;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LorefireTmpCleanupTest extends TestCase
{
    public function test_python_sweep_clears_legacy_ffmpeg_dirs_and_reuses_alias(): void
    {
        $script = base_path(implode(DIRECTORY_SEPARATOR, ['resources', 'python', 'lorefire_tmp.py']));
        $this->assertFileExists($script);

        $sandbox = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lorefire-tmp-test-'.uniqid('', true);
        $appTmp = $sandbox.DIRECTORY_SEPARATOR.'app';
        $sysTmp = $sandbox.DIRECTORY_SEPARATOR.'sys';
        mkdir($appTmp, 0755, true);
        mkdir($sysTmp, 0755, true);

        $legacy = $sysTmp.DIRECTORY_SEPARATOR.'lorefire_ffmpeg_slice1';
        mkdir($legacy, 0755, true);
        file_put_contents($legacy.DIRECTORY_SEPARATOR.'ffmpeg', str_repeat('B', 64));

        $fakeFfmpeg = $sandbox.DIRECTORY_SEPARATOR.'imageio-ffmpeg.bin';
        file_put_contents($fakeFfmpeg, "#!/bin/sh\necho ffmpeg\n");
        chmod($fakeFfmpeg, 0755);

        $env = array_merge(getenv() ?: [], [
            'LOREFIRE_TMP' => $appTmp,
            'TMPDIR' => $sysTmp,
            'TMP' => $sysTmp,
            'TEMP' => $sysTmp,
            'LOREFIRE_FFMPEG_SRC' => $fakeFfmpeg,
        ]);

        $sweep = new Process(['python3', $script, 'sweep', '--max-age', '0']);
        $sweep->setEnv($env);
        $sweep->run();
        $this->assertTrue($sweep->isSuccessful(), $sweep->getErrorOutput());
        $this->assertDirectoryDoesNotExist($legacy);

        $ensure = new Process(['python3', $script, 'ensure-ffmpeg']);
        $ensure->setEnv($env);
        $ensure->run();
        $this->assertTrue($ensure->isSuccessful(), $ensure->getErrorOutput());
        $alias = trim($ensure->getOutput());
        $this->assertNotSame('', $alias);
        $this->assertFileExists($alias);
        $this->assertStringContainsString('ffmpeg-alias', $alias);
        $this->assertStringStartsWith($appTmp, $alias);

        $leftovers = glob($sysTmp.DIRECTORY_SEPARATOR.'lorefire_ffmpeg_*') ?: [];
        $this->assertSame([], $leftovers);

        $again = new Process(['python3', $script, 'ensure-ffmpeg']);
        $again->setEnv($env);
        $again->run();
        $this->assertTrue($again->isSuccessful(), $again->getErrorOutput());
        $this->assertSame($alias, trim($again->getOutput()));
        $this->assertSame([], glob($sysTmp.DIRECTORY_SEPARATOR.'lorefire_ffmpeg_*') ?: []);

        AppTemp::removePath($sandbox);
    }
}
