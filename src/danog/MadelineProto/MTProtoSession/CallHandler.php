<?php

/**
 * CallHandler module.
 *
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2019 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 *
 * @link      https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto\MTProtoSession;

use Amp\Deferred;
use Amp\Promise;
use Amp\Success;
use danog\MadelineProto\Async\Parameters;
use danog\MadelineProto\Tools;

use function Amp\Promise\all;

/**
 * Manages method and object calls.
 */
trait CallHandler
{
    /**
     * Recall method.
     *
     * @param string $watcherId Watcher ID for defer
     * @param array  $args      Args
     *
     * @return void
     */
    public function method_recall(string $watcherId, array $args)
    {
        $message_id = $args['message_id'];
        $postpone = $args['postpone'] ?? false;
        $datacenter = $args['datacenter'] ?? false;
        if ($datacenter === $this->datacenter) {
            $datacenter = false;
        }

        $message_ids = $this->outgoing_messages[$message_id]['container'] ?? [$message_id];

        foreach ($message_ids as $message_id) {
            if (isset($this->outgoing_messages[$message_id]['body'])) {
                if ($datacenter) {
                    $res = $this->API->datacenter->getConnection($datacenter)->sendMessage($this->outgoing_messages[$message_id], false);
                } else {
                    $res = $this->sendMessage($this->outgoing_messages[$message_id], false);
                }
                $this->callFork($res);
                $this->ack_outgoing_message_id($message_id);
                $this->got_response_for_outgoing_message_id($message_id);
            } else {
                $this->logger->logger('Could not resend '.(isset($this->outgoing_messages[$message_id]['_']) ? $this->outgoing_messages[$message_id]['_'] : $message_id));
            }
        }
        if (!$postpone) {
            if ($datacenter) {
                $this->API->datacenter->getDataCenterConnection($datacenter)->flush();
            } else {
                $this->flush();
            }
        }
    }

    /**
     * Call method and wait asynchronously for response.
     *
     * If the $aargs['noResponse'] is true, will not wait for a response.
     *
     * @param string $method Method name
     * @param array  $args   Arguments
     * @param array  $aargs  Additional arguments
     *
     * @return Promise
     */
    public function method_call_async_read(string $method, $args = [], array $aargs = ['msg_id' => null]): Promise
    {
        $deferred = new Deferred();
        $this->method_call_async_write($method, $args, $aargs)->onResolve(function ($e, $read_deferred) use ($deferred) {
            if ($e) {
                $deferred->fail($e);
            } else {
                if (\is_array($read_deferred)) {
                    $read_deferred = \array_map(
                        function ($value) {
                            return $value->promise();
                        },
                        $read_deferred
                    );
                    $deferred->resolve(all($read_deferred));
                } else {
                    $deferred->resolve($read_deferred->promise());
                }
            }
        });

        return ($aargs['noResponse'] ?? false) ? new Success() : $deferred->promise();
    }

    /**
     * Call method and make sure it is asynchronously sent.
     *
     * @param string $method Method name
     * @param array  $args   Arguments
     * @param array  $aargs  Additional arguments
     *
     * @return Promise
     */
    public function method_call_async_write(string $method, $args = [], array $aargs = ['msg_id' => null]): Promise
    {
        return $this->call($this->method_call_async_write_generator($method, $args, $aargs));
    }

    /**
     * Call method and make sure it is asynchronously sent (generator).
     *
     * @param string $method Method name
     * @param array  $args   Arguments
     * @param array  $aargs  Additional arguments
     *
     * @return Generator
     */
    public function method_call_async_write_generator(string $method, $args = [], array $aargs = ['msg_id' => null]): \Generator
    {
        if (\is_array($args)
            && isset($args['id']['_'])
            && isset($args['id']['dc_id'])
            && $args['id']['_'] === 'inputBotInlineMessageID'
            && $this->datacenter !== $args['id']['dc_id']
        ) {
            return yield $this->API->datacenter->getConnection($args['id']['dc_id'])->method_call_async_write_generator($method, $args, $aargs);
        }
        if (($aargs['file'] ?? false) && !$this->isMedia() && $this->API->datacenter->has($this->datacenter.'_media')) {
            $this->logger->logger('Using media DC');
            return yield $this->API->datacenter->getConnection($this->datacenter.'_media')->method_call_async_write_generator($method, $args, $aargs);
        }
        if (\in_array($method, ['messages.setEncryptedTyping', 'messages.readEncryptedHistory', 'messages.sendEncrypted', 'messages.sendEncryptedFile', 'messages.sendEncryptedService', 'messages.receivedQueue'])) {
            $aargs['queue'] = 'secret';
        }

        if (\is_array($args)) {
            if (isset($args['multiple'])) {
                $aargs['multiple'] = true;
            }
            if (isset($args['message']) && \is_string($args['message']) && \mb_strlen($args['message'], 'UTF-8') > (yield $this->API->get_config_async())['message_length_max'] && \mb_strlen((yield $this->API->parse_mode_async($args))['message'], 'UTF-8') > (yield $this->API->get_config_async())['message_length_max']) {
                $args = yield $this->API->split_to_chunks_async($args);
                $promises = [];
                $aargs['queue'] = $method;
                $aargs['multiple'] = true;
            }
            if (isset($aargs['multiple'])) {
                $new_aargs = $aargs;
                $new_aargs['postpone'] = true;
                unset($new_aargs['multiple']);

                if (isset($args['multiple'])) {
                    unset($args['multiple']);
                }
                foreach ($args as $single_args) {
                    $promises[] = $this->method_call_async_write($method, $single_args, $new_aargs);
                }

                if (!isset($aargs['postpone'])) {
                    $this->writer->resume();
                }

                return yield all($promises);
            }
            $args = yield $this->API->botAPI_to_MTProto_async($args);
            if (isset($args['ping_id']) && \is_int($args['ping_id'])) {
                $args['ping_id'] = Tools::pack_signed_long($args['ping_id']);
            }
        }

        $deferred = new Deferred();
        $message = \array_merge(
            $aargs,
            [
                '_' => $method,
                'type' => $this->API->methods->find_by_method($method)['type'],
                'content_related' => $this->content_related($method),
                'promise' => $deferred,
                'method' => true,
                'unencrypted' => !$this->shared->hasTempAuthKey() && \strpos($method, '.') === false
            ]
        );

        if (\is_object($args) && $args instanceof Parameters) {
            $message['body'] = yield $args->fetchParameters();
        } else {
            $message['body'] = $args;
        }

        if (($method === 'users.getUsers' && $args === ['id' => [['_' => 'inputUserSelf']]]) || $method === 'auth.exportAuthorization' || $method === 'updates.getDifference') {
            $message['user_related'] = true;
        }
        $aargs['postpone'] = $aargs['postpone'] ?? false;
        $deferred = yield $this->sendMessage($message, !$aargs['postpone']);

        $this->checker->resume();

        return $deferred;
    }

    /**
     * Send object and make sure it is asynchronously sent (generator).
     *
     * @param string $object Object name
     * @param array  $args   Arguments
     * @param array  $aargs  Additional arguments
     *
     * @return Promise
     */
    public function object_call_async(string $object, $args = [], array $aargs = ['msg_id' => null]): \Generator
    {
        $message = ['_' => $object, 'body' => $args, 'content_related' => $this->content_related($object), 'unencrypted' => !$this->shared->hasTempAuthKey(), 'method' => false];
        if (isset($aargs['promise'])) {
            $message['promise'] = $aargs['promise'];
        }

        $aargs['postpone'] = $aargs['postpone'] ?? false;
        return $this->sendMessage($message, !$aargs['postpone']);
    }
}
