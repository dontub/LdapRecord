<?php

namespace LdapRecord;

use ErrorException;

class Ldap implements LdapInterface
{
    /**
     * The LDAP host that is currently connected.
     *
     * @var string|null
     */
    protected $host;

    /**
     * The active LDAP connection.
     *
     * @var resource|null
     */
    protected $connection;

    /**
     * The bound status of the connection.
     *
     * @var bool
     */
    protected $bound = false;

    /**
     * Whether the connection must be bound over SSL.
     *
     * @var bool
     */
    protected $useSSL = false;

    /**
     * Whether the connection must be bound over TLS.
     *
     * @var bool
     */
    protected $useTLS = false;

    /**
     * {@inheritdoc}
     */
    public function isUsingSSL()
    {
        return $this->useSSL;
    }

    /**
     * {@inheritdoc}
     */
    public function isUsingTLS()
    {
        return $this->useTLS;
    }

    /**
     * {@inheritdoc}
     */
    public function isBound()
    {
        return $this->bound;
    }

    /**
     * {@inheritdoc}
     */
    public function canChangePasswords()
    {
        return $this->isUsingSSL() || $this->isUsingTLS();
    }

    /**
     * {@inheritdoc}
     */
    public function ssl($enabled = true)
    {
        $this->useSSL = $enabled;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function tls($enabled = true)
    {
        $this->useTLS = $enabled;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * {@inheritdoc}
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * {@inheritdoc}
     */
    public function getEntries($searchResults)
    {
        return $this->executeFailableOperation('ldap_get_entries', $this->connection, $searchResults);
    }

    /**
     * {@inheritdoc}
     */
    public function getFirstEntry($searchResults)
    {
        return $this->executeFailableOperation('ldap_first_entry', $this->connection, $searchResults);
    }

    /**
     * {@inheritdoc}
     */
    public function getNextEntry($entry)
    {
        return $this->executeFailableOperation('ldap_next_entry', $this->connection, $entry);
    }

    /**
     * {@inheritdoc}
     */
    public function getAttributes($entry)
    {
        return $this->executeFailableOperation('ldap_get_attributes', $this->connection, $entry);
    }

    /**
     * {@inheritdoc}
     */
    public function countEntries($searchResults)
    {
        return $this->executeFailableOperation('ldap_count_entries', $this->connection, $searchResults);
    }

    /**
     * {@inheritdoc}
     */
    public function compare($dn, $attribute, $value)
    {
        return $this->executeFailableOperation('ldap_compare', $this->connection, $dn, $attribute, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function getLastError()
    {
        return ldap_error($this->connection);
    }

    /**
     * {@inheritdoc}
     */
    public function getDetailedError()
    {
        // If the returned error number is zero, the last LDAP operation
        // succeeded. In such case we won't return a detailed error.
        if ($number = $this->errNo()) {
            $this->getOption(LDAP_OPT_DIAGNOSTIC_MESSAGE, $message);

            return new DetailedError($number, $this->err2Str($number), $message);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getValuesLen($entry, $attribute)
    {
        return $this->executeFailableOperation('ldap_get_values_len', $this->connection, $entry, $attribute);
    }

    /**
     * {@inheritdoc}
     */
    public function setOption($option, $value)
    {
        return ldap_set_option($this->connection, $option, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function setOptions(array $options = [])
    {
        foreach ($options as $option => $value) {
            $this->setOption($option, $value);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getOption($option, &$value)
    {
        ldap_get_option($this->connection, $option, $value);

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function setRebindCallback(callable $callback)
    {
        return ldap_set_rebind_proc($this->connection, $callback);
    }

    /**
     * {@inheritdoc}
     */
    public function startTLS()
    {
        return $this->executeFailableOperation('ldap_start_tls', $this->connection);
    }

    /**
     * {@inheritdoc}
     */
    public function connect($hosts = [], $port = 389)
    {
        $this->host = $this->getConnectionString($hosts, $this->getProtocol(), $port);

        $this->bound = false;

        return $this->connection = $this->executeFailableOperation('ldap_connect', $this->host);
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        $result = is_resource($this->connection) ? @ldap_close($this->connection) : false;

        $this->connection = null;
        $this->bound = false;

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function search($dn, $filter, array $fields, $onlyAttributes = false, $size = 0, $time = 0, $deref = null, $serverControls = [])
    {
        // Since PHP 7.3 has added further parameters to the ldap_search
        // method, we'll pass in all the given parameters to support
        // earlier versions of PHP that doesn't yet support them.
        return $this->executeFailableOperation('ldap_search', $this->connection, ...func_get_args());
    }

    /**
     * {@inheritdoc}
     */
    public function listing($dn, $filter, array $fields, $onlyAttributes = false, $size = 0, $time = 0)
    {
        return $this->executeFailableOperation(
            'ldap_list',
            $this->connection,
            $dn, $filter, $fields, $onlyAttributes, $size, $time
        );
    }

    /**
     * {@inheritdoc}
     */
    public function read($dn, $filter, array $fields, $onlyAttributes = false, $size = 0, $time = 0)
    {
        return $this->executeFailableOperation(
            'ldap_read',
            $this->connection,
            $dn, $filter, $fields, $onlyAttributes, $size, $time
        );
    }

    /**
     * {@inheritdoc}
     */
    public function bind($username, $password)
    {
        return $this->executeFailableOperation(
            'ldap_bind',
            $this->connection,
            $username, html_entity_decode($password)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function add($dn, array $entry)
    {
        return $this->executeFailableOperation('ldap_add', $this->connection, $dn, $entry);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($dn)
    {
        return $this->executeFailableOperation('ldap_delete', $this->connection, $dn);
    }

    /**
     * {@inheritdoc}
     */
    public function rename($dn, $newRdn, $newParent, $deleteOldRdn = false)
    {
        return $this->executeFailableOperation(
            'ldap_rename',
            $this->connection,
            $dn, $newRdn, $newParent, $deleteOldRdn
        );
    }

    /**
     * {@inheritdoc}
     */
    public function modify($dn, array $entry)
    {
        return $this->executeFailableOperation('ldap_modify', $this->connection, $dn, $entry);
    }

    /**
     * {@inheritdoc}
     */
    public function modifyBatch($dn, array $values)
    {
        return $this->executeFailableOperation('ldap_modify_batch', $this->connection, $dn, $values);
    }

    /**
     * {@inheritdoc}
     */
    public function modAdd($dn, array $entry)
    {
        return $this->executeFailableOperation('ldap_mod_add', $this->connection, $dn, $entry);
    }

    /**
     * {@inheritdoc}
     */
    public function modReplace($dn, array $entry)
    {
        return $this->executeFailableOperation('ldap_mod_replace', $this->connection, $dn, $entry);
    }

    /**
     * {@inheritdoc}
     */
    public function modDelete($dn, array $entry)
    {
        return $this->executeFailableOperation('ldap_mod_del', $this->connection, $dn, $entry);
    }

    /**
     * {@inheritdoc}
     */
    public function parseResult($result, $errorCode, $dn, $errorMessage, $refs, $serverControls = [])
    {
        return $this->executeFailableOperation(
            'ldap_parse_result',
            $this->connection,
            $result, $errorCode, $dn, $errorMessage, $refs, $serverControls
        );
    }

    /**
     * {@inheritdoc}
     */
    public function controlPagedResult($pageSize = 1000, $isCritical = false, $cookie = '')
    {
        return $this->executeFailableOperation(
            'ldap_control_paged_result',
            $this->connection,
            $pageSize, $isCritical, $cookie
        );
    }

    /**
     * {@inheritdoc}
     */
    public function controlPagedResultResponse($result, &$cookie)
    {
        return $this->executeFailableOperation(
            'ldap_control_paged_result_response',
            $this->connection,
            $result, $cookie
        );
    }

    /**
     * {@inheritdoc}
     */
    public function freeResult($result)
    {
        return ldap_free_result($result);
    }

    /**
     * {@inheritdoc}
     */
    public function errNo()
    {
        return ldap_errno($this->connection);
    }

    /**
     * {@inheritdoc}
     */
    public function getExtendedError()
    {
        return $this->getDiagnosticMessage();
    }

    /**
     * {@inheritdoc}
     */
    public function getExtendedErrorHex()
    {
        if (preg_match("/(?<=data\s).*?(?=\,)/", $this->getExtendedError(), $code)) {
            return $code[0];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getExtendedErrorCode()
    {
        return $this->extractDiagnosticCode($this->getExtendedError());
    }

    /**
     * {@inheritdoc}
     */
    public function err2Str($number)
    {
        return ldap_err2str($number);
    }

    /**
     * {@inheritdoc}
     */
    public function getDiagnosticMessage()
    {
        $this->getOption(LDAP_OPT_ERROR_STRING, $message);

        return $message;
    }

    /**
     * {@inheritdoc}
     */
    public function extractDiagnosticCode($message)
    {
        preg_match('/^([\da-fA-F]+):/', $message, $matches);

        return isset($matches[1]) ? $matches[1] : false;
    }

    /**
     * Returns the LDAP protocol to utilize for the current connection.
     *
     * @return string
     */
    public function getProtocol()
    {
        return $this->isUsingSSL() ? $this::PROTOCOL_SSL : $this::PROTOCOL;
    }

    /**
     * Convert warnings to exceptions for the given operation.
     *
     * @param string $method
     * @param mixed  $args
     *
     * @throws ErrorException
     *
     * @return mixed
     */
    protected function executeFailableOperation($method, ...$args)
    {
        set_error_handler(function ($severity, $message, $file, $line) {
            if (!$this->causedBySizeLimit($message)) {
                throw new ErrorException($message, $severity, $severity, $file, $line);
            }
        });

        $result = $method(...$args);

        restore_error_handler();

        return $result;
    }

    /**
     * Determine if the given error message was a size limit warning.
     *
     * @param $message
     *
     * @return bool
     */
    protected function causedBySizeLimit($message)
    {
        return strpos($message, 'Partial search results returned') !== false;
    }

    /**
     * Generates an LDAP connection string for each host given.
     *
     * @param string|array $hosts
     * @param string       $protocol
     * @param string       $port
     *
     * @return string
     */
    protected function getConnectionString($hosts, $protocol, $port)
    {
        // If we are using SSL and using the default port, we
        // will override it to use the default SSL port.
        if ($this->isUsingSSL() && $port == 389) {
            $port = static::PORT_SSL;
        }

        // Normalize hosts into an array.
        $hosts = is_array($hosts) ? $hosts : [$hosts];

        $hosts = array_map(function ($host) use ($protocol, $port) {
            return "{$protocol}{$host}:{$port}";
        }, $hosts);

        return implode(' ', $hosts);
    }
}
