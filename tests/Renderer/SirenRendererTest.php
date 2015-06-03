<?php

namespace BEAR\Resource;

use BEAR\SirenRenderer\Provide\Representation\SirenRenderer;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Cache\ArrayCache;

class Root extends ResourceObject
{
    public function onGet()
    {
        $this['one'] = 1;
        $this['two'] = new Request(
            new Invoker(new NamedParameter(new ArrayCache, new VoidParamHandler)),
            new Child
        );
        return $this;
    }
}
class Child extends ResourceObject
{
    public function onGet()
    {
        $this['tree'] = 3;
        return $this;
    }
}
class SirenRendererTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var
     */
    private $ro;
    protected function setUp()
    {
        $this->ro = new Root;
        $this->ro->setRenderer(new SirenRenderer(new AnnotationReader()));
    }
    public function testRender()
    {
        $ro = $this->ro->onGet();
        $data = (string) $ro;
        $expected = '{"one":1,"two":{"tree":3}}';
        $this->assertSame($expected, $data);
    }
    public function testRenderScalar()
    {
        $this->ro->body = 1;
        $data = (string) $this->ro;
        $expected = '{"value":1}';
        $this->assertSame($expected, $data);
    }
    public function testError()
    {
        $this->ro['inf'] = log(0);
        $data = (string) $this->ro;
        $this->assertInternalType('string', $data);
    }
    public function testHeader()
    {
        /* @var $ro ResourceObject */
        $ro = $this->ro->onGet();
        (string) $ro;
        $expected = 'application/vnd.siren+json';
        $this->assertSame($expected, $ro->headers['content-type']);
    }
}