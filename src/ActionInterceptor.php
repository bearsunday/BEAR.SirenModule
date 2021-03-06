<?php

/**
 * This file is part of the BEAR.SirenModule package
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\SirenModule;

use BEAR\Resource\Exception\BadRequestException;
use BEAR\Resource\FactoryInterface;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\SirenModule\Annotation\Action;
use BEAR\SirenModule\Annotation\Field;
use BEAR\SirenModule\Annotation\Name;
use BEAR\SirenModule\Annotation\Title;
use Doctrine\Common\Annotations\Reader;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

final class ActionInterceptor implements MethodInterceptor
{
    /**
     * @var \BEAR\Resource\ResourceInterface
     */
    private $resource;

    /**
     * @var Reader
     */
    private $reader;

    /**
     * @param ResourceInterface $resource
     * @param Reader            $reader
     * @param FactoryInterface  $factory
     */
    public function __construct(ResourceInterface $resource, Reader $reader, FactoryInterface $factory)
    {
        $this->resource = $resource;
        $this->reader = $reader;
        $this->factory = $factory;
    }

    /**
     * {@inheritdoc}
     */
    public function invoke(MethodInvocation $invocation)
    {
        /** @var $resourceObject ResourceObject */
        $resourceObject = $invocation->getThis();
        $method = $invocation->getMethod();
        $query = $this->getArgsByInvocation($invocation);
        $actions = $this->reader->getMethodAnnotations($method);

        $this->addActions($actions, $resourceObject, $query);
        // request (method can modify embedded resource)
        $result = $invocation->proceed();

        return $result;
    }

    /**
     * @param Action[]       $actions
     * @param ResourceObject $resourceObject
     * @param array          $query
     */
    private function addActions(array $actions, ResourceObject $resourceObject, array $query)
    {
        foreach ($actions as $action) {
            /* @var $action Action */
            if (! $action instanceof Action) {
                continue;
            }
            try {
                // Get Uri for related resource
                $templateUri = $this->getFullUri($action->src, $resourceObject);
                $uri = uri_template($templateUri, $query);

                // Get Method from action
                $requestMethod = $action->method;
                $requestMethod = 'on' . ucfirst($requestMethod);

                // Get resource object for action
                $actionResource = $this->factory->newInstance($uri);
                $ref = new \ReflectionMethod($actionResource, $requestMethod);
                $annotations = $this->reader->getMethodAnnotations($ref);

                $data = [];

                foreach ($annotations as $annotation) {
                    if ($annotation instanceof Name) {
                        $data['name'] = $annotation->value;
                    }
                    if ($annotation instanceof Title) {
                        $data['title'] = $annotation->value;
                    }
                    if ($annotation instanceof Field) {
                        $field = [];
                        $field['name'] = $annotation->name;
                        $field['type'] = $annotation->type;
                        $field['value'] = $this->replaceParameters($annotation->value, $query);
                        $data['fields'][] = $field;
                    }
                    $data['method'] = strtoupper($action->method);
                    $data['href'] = $this->replaceQueryParameter($action->src, $query);
                }

                $resourceObject->body['siren']['actions'][] = $data;
            } catch (BadRequestException $e) {
                // wrap ResourceNotFound or Uri exception
                //throw new ActionException($action->src, 500, $e);
            }
        }
    }

    /**
     * @param $parameter
     * @param $query
     *
     * @return mixed
     */
    private function replaceParameters($parameter, $query)
    {
        foreach ($query as $key => $value) {
            return str_replace('{?' . $key . '}', $value, $parameter);
        }
    }

    /**
     * @param $src
     * @param $query
     *
     * @return mixed
     */
    private function replaceQueryParameter($src, $query)
    {
        foreach ($query as $key => $value) {
            $src = str_replace('{?' . $key . '}', '?' . $key . '=' . $value, $src);
        }

        return $src;
    }

    /**
     * @param string         $uri
     * @param ResourceObject $resourceObject
     *
     * @return string
     */
    private function getFullUri($uri, ResourceObject $resourceObject)
    {
        if (substr($uri, 0, 1) == '/') {
            $uri = "{$resourceObject->uri->scheme}://{$resourceObject->uri->host}" . $uri;
        }

        return $uri;
    }
    /**
     * @param MethodInvocation $invocation
     *
     * @return array
     */
    private function getArgsByInvocation(MethodInvocation $invocation)
    {
        $args = $invocation->getArguments()->getArrayCopy();
        $params = $invocation->getMethod()->getParameters();
        $namedParameters = [];
        foreach ($params as $param) {
            $namedParameters[$param->name] = array_shift($args);
        }

        return $namedParameters;
    }
}
