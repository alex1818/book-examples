<?php

namespace SocketProgrammingHandbook;

final class StreamSelectLoop {
    private $readStreams = [];
    private $readHandlers = [];
    private $writeStreams = [];
    private $writeHandlers = [];

    function addReadStream($stream, callable $handler) {
        if (empty($this->readStreams[(int) $stream])) {
            $this->readStreams[(int) $stream] = $stream;
            $this->readHandlers[(int) $stream] = $handler;
        }
    }

    function addWriteStream($stream, callable $handler) {
        if (empty($this->writeStreams[(int) $stream])) {
            $this->writeStreams[(int) $stream] = $stream;
            $this->writeHandlers[(int) $stream] = $handler;
        }
    }

    function removeReadStream($stream) {
        unset($this->readStreams[(int) $stream]);
    }

    function removeWriteStream($stream) {
        unset($this->writeStreams[(int) $stream]);
    }

    function removeStream($stream) {
        $this->removeReadStream($stream);
        $this->removeWriteStream($stream);
    }

    function run() {
        for (;;) {
            $read = $this->readStreams;
            $write = $this->writeStreams;
            $except = null;

            if ($read || $write) {
                @stream_select($read, $write, $except, 0, 100);

                foreach ($read as $stream) {
                    call_user_func($this->readHandlers[(int) $stream], $stream);
                }

                foreach ($write as $stream) {
                    call_user_func($this->writeHandlers[(int) $stream], $stream);
                }
            } else {
                usleep(100);
            }
        }
    }
}

$main = function () {
    $port = @$_SERVER['PORT'] ?: 1337;
    $server = @stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);

    if (false === $server) {
        // Write error message to STDERR and exit, just like UNIX programs usually do
        fprintf(STDERR, "Error connecting to socket: %d %s\n", $errno, $errstr);
        exit(1);
    }

    // Make sure calling stream_socket_accept can't block when called on this server stream,
    // in case someone wants to add another server stream to the reactor
    // (maybe listening on another port, implementing another protocol ;))
    stream_set_blocking($server, 0);

    printf("Listening on port %d\n", $port);

    $loop = new StreamSelectLoop();

    // This code runs when the socket has a connection ready for accepting
    $loop->addReadStream($server, function ($server) use ($loop) {
        $conn = @stream_socket_accept($server, -1, $peer);
        $buf = '';

        // This runs when a read can be made without blocking:
        $loop->addReadStream($conn, function ($conn) use ($loop, &$buf) {
            $buf = @fread($conn, 4096) ?: '';

            if (@feof($conn)) {
                $loop->removeStream($conn);
                fclose($conn);
            }
        });

        // This runs when a write can be made without blocking:
        $loop->addWriteStream($conn, function ($conn) use ($loop, &$buf) {
            if (strlen($buf) > 0) {
                @fwrite($conn, $buf);
                $buf = '';
            }

            if (@feof($conn)) {
                $loop->removeStream($conn);
                fclose($conn);
            }
        });
    });

    $loop->run();
};

if (__FILE__ === realpath($argv[0])) {
    $main();
}
