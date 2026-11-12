<?php

declare(strict_types=1);

use Bpmore\A11yReport\Storage\ReportFiles;
use Illuminate\Support\Facades\Storage;

const DURABLE_REPORT_PATH = 'a11y-report/reports/49cb3134-7f3e-42d2-8d58-54268c9fcc83.html';

beforeEach(function () {
    tempStorage();
    Storage::fake('report_private');
    config()->set('statamic-a11y-report.files.disk', 'report_private');
});

it('restores exact private HTML after every local copy disappears', function () {
    $files = new ReportFiles(app());
    $html = "<html lang=\"en\"><body>Private report: café ☀</body></html>\n";
    $files->put(DURABLE_REPORT_PATH, $html);
    unlink(app()->storagePath(DURABLE_REPORT_PATH));

    $restored = (new ReportFiles(app()))->absolutePath(DURABLE_REPORT_PATH);

    expect(file_get_contents($restored))->toBe($html);
    expect(fileperms($restored) & 0777)->toBe(0600);
    Storage::disk('report_private')->assertMissing(DURABLE_REPORT_PATH);
    Storage::disk('report_private')->assertExists(DURABLE_REPORT_PATH.'.json');
});

it('refuses corrupted remote bytes instead of serving an old cached report', function () {
    $files = new ReportFiles(app());
    $files->put(DURABLE_REPORT_PATH, 'correct original report');
    $disk = Storage::disk('report_private');
    $data = json_decode($disk->get(DURABLE_REPORT_PATH.'.json'), true);
    $data['base64'] = base64_encode('CORRECT original report');
    $disk->put(DURABLE_REPORT_PATH.'.json', json_encode($data));

    expect(fn () => $files->absolutePath(DURABLE_REPORT_PATH))->toThrow(RuntimeException::class);
});

it('does not claim success when durable storage rejects the write', function () {
    $disk = Mockery::mock();
    $disk->shouldReceive('put')->once()->andReturn(false);
    Storage::shouldReceive('disk')->with('report_private')->andReturn($disk);

    expect(fn () => (new ReportFiles(app()))->put(DURABLE_REPORT_PATH, 'report'))
        ->toThrow(RuntimeException::class);
    expect(is_file(app()->storagePath(DURABLE_REPORT_PATH)))->toBeFalse();
});

it('rejects paths outside the report folder and missing remote reports', function () {
    $files = new ReportFiles(app());
    expect(fn () => $files->absolutePath('../private-file'))->toThrow(RuntimeException::class);
    expect($files->absolutePath(null))->toBeNull();
    expect($files->absolutePath(DURABLE_REPORT_PATH))->toBeNull();
});

it('keeps local report storage when no durable disk is configured', function () {
    config()->set('statamic-a11y-report.files.disk', null);
    $files = new ReportFiles(app());
    $files->put(DURABLE_REPORT_PATH, 'local report');

    expect(file_get_contents($files->absolutePath(DURABLE_REPORT_PATH)))->toBe('local report');
    expect(Storage::disk('report_private')->allFiles())->toBe([]);
});
