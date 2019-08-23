<?php

namespace BeBound;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Sdk
{
    private $id = null;
    private $secret = null;
    private $version = null;
    private $auth = true;
    private $data = array();
    private $logger;
    private $beBound = false;
    private $headers = array();
    const PUSH_URL = 'https://africa-bbml.be-bound.com/';

    public function __construct(array $variables, LoggerInterface $logger = null)
    {
        foreach ($variables as $key => $variable) {
            switch ($key) {
                case 'id':
                case 'secret':
                case 'version':
                case 'auth':
                    $this->$key = $variable;
                    break;
            }
        }
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @return Sdk
     * @throws BeBoundException
     */
    public function init(): self
    {
        $this->data = \json_decode(\file_get_contents('php://input'), true);

        if (!(\is_array($this->data) && \JSON_ERROR_NONE === \json_last_error())) {
            $this->logger->info('Json not valid');

            return $this;
        }

        $this->logger->debug('content of json: ' . \json_encode($this->data));

        $headers = $this->getAllHeaders();

        if (isset($headers['beapp-message'])) {
            $this->beBound = true;
            if ((int)$this->version !== (int)($headers['beapp-version'] ?? 0)) {
                throw new BeBoundException('Wrong be-app version');
            }

            $this->headers['beapp-version'] = $headers['beapp-version'];
            $this->headers['beapp-message'] = $headers['beapp-message'];
            $this->headers['device-id'] = $headers['device-id'];
            $this->headers['authorization'] = $headers['authorization'];

            $authUser = $_SERVER['PHP_AUTH_USER'];
            $authPassword = $_SERVER['PHP_AUTH_PW'];

            if ( $this->auth !== false &&
                !($authPassword === $this->secret
                    && $authUser === $this->id)
            ) {
                throw new BeBoundException('Wrong be-app id or be-app secret');
            }
        }

        return $this;
    }

    /**
     * \getallheaders() only works with apache, this also works with nginx
     * @return array
     */
    private function getAllHeaders(): array
    {
        if (!\is_array($_SERVER)) {
            return array();
        }

        $headers = array();
        foreach ($_SERVER as $name => $value) {
            if (\strpos($name, 'HTTP_') === 0) {
                $key = \str_replace(' ', '-', \strtolower(\str_replace('_', ' ', \substr($name, 5))));
                $headers[$key] = $value;
            }
        }
        return $headers;
    }

    /**
     * @param string $message
     * @param string $deviceId
     * @param array $data
     * @throws BeBoundException
     */
    public function push(string $message, string $deviceId, array $data): void
    {
        $headers = array(
            'device-id' => $deviceId,
            'beapp-version' => (int)$this->version,
            'beapp-message' => $message,
            'Content-Type' => 'application/json',
        );
        try {
            $curl = \curl_init();
            \curl_setopt($curl, CURLOPT_POST, 1);
            \curl_setopt($curl, CURLOPT_POSTFIELDS, \json_encode($data));
            \curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            \curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            \curl_setopt($curl, CURLOPT_USERPWD, $this->id . ':' . $this->secret);
            \curl_setopt($curl, CURLOPT_URL, self::PUSH_URL);
            \curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            \curl_exec($curl);
            \curl_close($curl);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            throw new BeBoundException('Error while pushing: ' . $e->getMessage());
        }
    }

    /**
     * @return bool
     */
    public function isBeBound(): bool
    {
        return $this->beBound;
    }

    /**
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * @return string
     */
    public function getMessage(): string
    {
        return $this->headers['beapp-message'] ?? '';
    }

    /**
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }
}