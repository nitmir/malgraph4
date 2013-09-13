<?php
require_once 'src/core.php';

function processQueue($queue, $count, $logger, $callback)
{
	$processed = 0;
	while ($processed < $count)
	{
		$key = $queue->dequeue();
		if ($key === null)
		{
			break;
		}

		try
		{
			$okay = $callback($key);
			if ($okay)
			{
				++ $processed;
			}
		}
		catch (BadProcessorKeyException $e)
		{
			++ $processed;
			$logger->log('error: ' . $e->getMessage());
		}
		catch (DownloadFailureException $e)
		{
			++ $processed;
			$logger->log('error: ' . $e->getMessage());
			$queue->enqueue($key);
		}
		catch (Exception $e)
		{
			++ $processed;
			$logger->log('error');
			$logger->log($e);
			$queue->enqueue($key);
		}
	}
}

CronRunner::run(__FILE__, function($logger)
{
	$userProcessor = new UserProcessor();
	$mediaProcessors =
	[
		Media::Anime => new AnimeProcessor(),
		Media::Manga => new MangaProcessor()
	];

	$userQueue = new Queue(Config::$userQueuePath);
	$mediaQueue = new Queue(Config::$mediaQueuePath);

	#process users
	processQueue(
		$userQueue,
		Config::$usersPerCronRun,
		$logger,
		function($userName) use ($userProcessor, $mediaQueue, $logger)
		{
			$logger->logFragment('Processing user %s... ', $userName);

			#check if processed too soon
			$query = 'SELECT 0 FROM user WHERE LOWER(name) = LOWER(?)' .
				' AND processed >= DATETIME("now", "-1 days")';
			if (R::getAll($query, [$userName]))
			{
				$logger->log('too soon');
				return false;
			}

			#process the user
			$userContext = $userProcessor->process($userName);

			#remove associated cache
			$cache = new Cache();
			$cache->setPrefix($userName);
			foreach ($cache->getAllFiles() as $path)
			{
				unlink($path);
			}

			#append media to queue
			foreach (Media::getConstList() as $media)
			{
				foreach ($userContext->user->getMixedUserMedia($media) as $entry)
				{
					$mediaQueue->enqueue(TextHelper::serializeMediaId($entry));
				}
			}

			$logger->log('ok');
			return true;
		});

	#process media
	processQueue(
		$mediaQueue,
		Config::$mediaPerCronRun,
		$logger,
		function($key) use ($mediaProcessors, $logger)
		{
			list ($media, $malId) = TextHelper::deserializeMediaId($key);
			$logger->logFragment('Processing %s #%d... ', Media::toString($media), $malId);

			#check if processed too soon
			$query = 'SELECT 0 FROM media WHERE media = ? AND mal_id = ?' .
				' AND processed >= DATETIME("now", "-7 days")';
			if (R::getAll($query, [$media, $malId]))
			{
				$logger->log('too soon');
				return false;
			}

			#process the media
			$mediaProcessors[$media]->process($malId);

			$logger->log('ok');
			return true;
		});
});
