<?php namespace PHRETS\Parsers\GetObject;

use PHRETS\Http\Response as PHRETSResponse;

class MultipleStreamed
{
    // 20MB
    const DEFAULT_CHUNK_SIZE = 20000000;

    public function parse(PHRETSResponse $response, $chunkSize = null)
    {
        if (!$response->getBody()) {
            return;
        }

        $chunkSize = $chunkSize ?: self::DEFAULT_CHUNK_SIZE;

        $singleObjectParser = new Single();

        $isFirstChunk = true;
        $inProgressSegment = '';

        $boundary = $this->determineBoundary($response->getHeader('Content-Type'));
        $boundaryRegex = "/(\r\n|^)--{$boundary}-?-?(\r\n|$)/";
        // dummy preg_match() to test if the regex is valid
        if (preg_match($boundaryRegex, '') === false) {
            throw new \RuntimeException(
                sprintf(
                    'MLS-provided boundary `%s` interferes with regex',
                    $boundary
                )
            );
        }

        while (!$response->getBody()->eof()) {
            $chunk = $response->getBody()->read($chunkSize);

            // in all likelihood our previous chunk split a segment in half
            // this re-combines that segment from its two halves
            // NOTE: a single gigantic image (>100MB) could be problematic.
            // returning gigantic images as streams would require a drastically different API
            // e.g. first sending the image headers, then wrapping the HTTP stream
            // with our own stream (in order to stop it at the boundary), or
            // streaming the images to disk and providing userspace with filenames
            $chunk = $inProgressSegment . $chunk;

            $segments = preg_split($boundaryRegex, $chunk);

            // remove "preamble" left over by the preg_split()
            if ($isFirstChunk) {
                array_shift($segments);

                $isFirstChunk = false;
            }

            // the last segment at the end is most likely incomplete (and even in the
            // one in a million chance that it is technically complete (i.e. the chunk
            // ended just before the next boundary), there's little harm in processing
            // it in the next chunk)
            $inProgressSegment = array_pop($segments);

            // process remaining complete segments (if any; an incomplete segment could
            // have taken up the whole chunk)
            foreach ($segments as $segment) {
                // get Guzzle to parse this multipart section as if it's a whole HTTP message
                $psrResponse = \GuzzleHttp\Psr7\Message::parseResponse("HTTP/1.1 200 OK\r\n" . $segment . "\r\n");

                // hydrate a "single" object from this segment
                $phretsResponse = new PHRETSResponse($psrResponse);
                yield $singleObjectParser->parse($phretsResponse);

                unset($psrResponse);
                unset($phretsResponse);
                gc_collect_cycles();
            }

            unset($chunk);
            unset($segments);
            gc_collect_cycles();
        }

        // ignore the last in-progress segment ("epilogue" left over from preg_split())
    }

    private function determineBoundary($contentType)
    {
        preg_match('/boundary\=\"(.*?)\"/', $contentType, $matches);
        if (isset($matches[1])) {
            $boundary = $matches[1];
        } else {
            preg_match('/boundary\=(.*?)(\s|$|\;)/', $contentType, $matches);
            if (isset($matches[1])) {
                $boundary = $matches[1];
            } else {
                $boundary = null;
            }
        }

        // strip quotes off of the boundary
        return preg_replace('/^\"(.*?)\"$/', '\1', $boundary);
    }
}
