<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
$dbPath = '/var/lib/nfs3-migrate/migration.sqlite';
$logPath = '/var/log/nfs3-migrate/controller.log';
try {
    if (!is_readable($dbPath)) throw new RuntimeException('Migration database is not readable by the web server.');
    $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(3000);
    $run = $db->querySingle('SELECT * FROM runs ORDER BY started_at DESC,run_id DESC LIMIT 1', true);
    if (!$run) {
        echo json_encode(['status'=>'No run','workers'=>0,'total_bytes'=>0,'migrated_bytes'=>0,'throughput_mbps'=>0,'eta'=>null,'partitions'=>[],'log'=>tailLog($logPath)]);
        exit;
    }
    $total=(int)($run['total_bytes']??0);
    $doneBytes=0; $doneFiles=0; $doneJobs=0; $totalJobs=(int)($run['partitions']??0);
    $activeJobs=0; $pendingJobs=0; $workerRows=[];
    $notes=(string)($run['notes']??'');
    if (($run['mode']??'') === 'fpsync' || strpos($notes,'engine=fpsync') !== false) {
        if (preg_match('/fpsync_run_id=([0-9]+-[0-9]+)/',$notes,$m)) {
            $fpsyncId=$m[1];
            $root='/var/lib/nfs3-migrate/fpsync';
            $metaFile="$root/parts/$fpsyncId/run.meta";
            if (is_readable($metaFile)) {
                $meta=[];
                foreach (file($metaFile, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    if (strpos($line,'=') !== false) { [$k,$v]=explode('=',$line,2); $meta[$k]=$v; }
                }
                $total=(int)($meta['total_size']??$total);
                $totalJobs=(int)($meta['total_num_parts']??$totalJobs);
                $totalFiles=(int)($meta['total_num_files']??0);
            }
            $doneDir="$root/done/$fpsyncId";
            $workDir="$root/work/$fpsyncId";
            $queueDir="$root/queue/$fpsyncId";
            $doneNames=is_dir($doneDir) ? array_values(array_filter(scandir($doneDir) ?: [],fn($x)=>$x!=='.'&&$x!=='..'&&$x!=='fp_done'&&$x!=='info')) : [];
            $workNames=is_dir($workDir) ? array_values(array_filter(scandir($workDir) ?: [],fn($x)=>$x!=='.'&&$x!=='..'&&$x!=='fp_done'&&$x!=='info')) : [];
            $queueNames=is_dir($queueDir) ? array_values(array_filter(scandir($queueDir) ?: [],fn($x)=>$x!=='.'&&$x!=='..'&&$x!=='fp_done'&&$x!=='info'&&$x!=='sl_stop')) : [];
            $doneJobs=count($doneNames); $activeJobs=count($workNames); $pendingJobs=count($queueNames);
            foreach ($doneNames as $job) {
                $jobBytes=null; $jobFiles=null;
                $outFile="$root/log/$fpsyncId/$job.stdout";
                if (is_readable($outFile)) {
                    $outText=(string)file_get_contents($outFile);
                    if (preg_match('/Total transferred file size:\s*([0-9,]+) bytes/',$outText,$bm)) $jobBytes=(int)str_replace(',','',$bm[1]);
                    if (preg_match('/Number of regular files transferred:\s*([0-9,]+)/',$outText,$fm)) $jobFiles=(int)str_replace(',','',$fm[1]);
                }
                $mf="$root/parts/$fpsyncId/part.$job.meta";
                if (($jobBytes===null || $jobFiles===null) && is_readable($mf)) {
                    foreach (file($mf, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                        if ($jobBytes===null && strpos($line,'job_size=') === 0) $jobBytes=(int)substr($line,9);
                        if ($jobFiles===null && strpos($line,'job_files=') === 0) $jobFiles=(int)substr($line,10);
                    }
                }
                $doneBytes += $jobBytes ?? 0;
                $doneFiles += $jobFiles ?? 0;
            }
            foreach ($workNames as $job) $workerRows[]=['partition_id'=>$job,'status'=>'running','worker_pid'=>null];
            for($i=$activeJobs;$i<min((int)$run['workers'],24);$i++) $workerRows[]=['partition_id'=>'—','status'=>'pending','worker_pid'=>null];
            $states=[['status'=>'completed','jobs'=>$doneJobs,'bytes'=>$doneBytes,'files'=>$doneFiles],
                     ['status'=>'running','jobs'=>$activeJobs,'bytes'=>0,'files'=>0],
                     ['status'=>'pending','jobs'=>$pendingJobs,'bytes'=>0,'files'=>0]];
        } else {
            $states=[];
        }
    } else {
        $stmt = $db->prepare('SELECT status, COUNT(*) AS jobs, COALESCE(SUM(bytes_transferred),0) AS bytes, COALESCE(SUM(files_transferred),0) AS files FROM partitions WHERE run_id=:run GROUP BY status');
        $stmt->bindValue(':run', (string)$run['run_id'], SQLITE3_TEXT);
        $res = $stmt->execute();
        $states=[];
        while ($row=$res->fetchArray(SQLITE3_ASSOC)) {
            $states[]=$row;
            if ($row['status']==='completed') { $doneBytes=(int)$row['bytes']; $doneFiles=(int)$row['files']; }
        }
        $stmt = $db->prepare("SELECT partition_id,status,attempts,files_transferred,bytes_transferred,last_error,worker_pid,started_at FROM partitions WHERE run_id=:run AND status IN ('running','retrying','failed') ORDER BY partition_id LIMIT 40");
        $stmt->bindValue(':run',(string)$run['run_id'],SQLITE3_TEXT);
        $res=$stmt->execute();
        while($row=$res->fetchArray(SQLITE3_ASSOC)) $workerRows[]=$row;
    }
    $started=strtotime((string)$run['started_at']);
    $elapsed=max(1,time()-($started?:time()));
    $mbps=$doneBytes>0 ? ($doneBytes/$elapsed/1000000) : 0;
    $remaining=max(0,$total-$doneBytes);
    $etaSeconds=$mbps>0 ? (int)($remaining/($mbps*1000000)) : null;
    $eta=$etaSeconds!==null ? formatDuration($etaSeconds) : null;
    echo json_encode([
      'run_id'=>$run['run_id'],'mode'=>$run['mode'],'status'=>$run['status'],'workers'=>(int)$run['workers'],
      'total_bytes'=>$total,'migrated_bytes'=>$doneBytes,'files_transferred'=>$doneFiles,
      'throughput_mbps'=>round($mbps,2),'eta'=>$eta,'elapsed_seconds'=>$elapsed,
      'states'=>$states,'partitions'=>$workerRows,'done_jobs'=>$doneJobs,'total_jobs'=>$totalJobs,'total_files'=>$totalFiles??0,'active_jobs'=>$activeJobs,'pending_jobs'=>$pendingJobs,'log'=>tailLog($logPath, (string)($run['mode']??''), (string)($run['notes']??''))
    ], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['error'=>$e->getMessage(),'status'=>'Status unavailable']);
}
function tailLog(string $path, string $mode='', string $notes=''): string {
    $data=readTail($path,12000);
    if (($mode==='fpsync' || strpos($notes,'engine=fpsync') !== false) && preg_match('/fpsync_run_id=([0-9]+-[0-9]+)/',$notes,$m)) {
        $fp='/var/lib/nfs3-migrate/fpsync/log/'.$m[1].'/fpsync.log';
        $native=readTail($fp,12000);
        if ($native!=='') $data .= "\n\n--- fpsync native log ---\n".$native;
    }
    return $data!=='' ? substr($data,-12000) : 'No controller log available yet.';
}
function readTail(string $path, int $bytes): string {
    if (!is_readable($path)) return '';
    $fp=@fopen($path,'rb');
    if (!$fp) return '';
    $size=filesize($path);
    if ($size>$bytes) fseek($fp,-$bytes,SEEK_END);
    $data=(string)stream_get_contents($fp);
    fclose($fp);
    return $data;
}
function formatDuration(int $seconds): string {
    $days=intdiv($seconds,86400); $seconds%=86400;
    $hours=intdiv($seconds,3600); $seconds%=3600;
    $minutes=intdiv($seconds,60);
    return $days>0 ? "{$days}d {$hours}h" : ($hours>0 ? "{$hours}h {$minutes}m" : "{$minutes}m");
}
