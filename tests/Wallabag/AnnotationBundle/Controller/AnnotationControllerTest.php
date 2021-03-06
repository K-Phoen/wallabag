<?php

namespace Tests\AnnotationBundle\Controller;

use Tests\Wallabag\AnnotationBundle\WallabagAnnotationTestCase;
use Wallabag\AnnotationBundle\Entity\Annotation;
use Wallabag\CoreBundle\Entity\Entry;

class AnnotationControllerTest extends WallabagAnnotationTestCase
{
    /**
     * This data provider allow to tests annotation from the :
     *     - API POV (when user use the api to manage annotations)
     *     - and User POV (when user use the web interface - using javascript - to manage annotations).
     */
    public function dataForEachAnnotations()
    {
        return [
            ['/api/annotations'],
            ['annotations'],
        ];
    }

    /**
     * Test fetching annotations for an entry.
     *
     * @dataProvider dataForEachAnnotations
     */
    public function testGetAnnotations($prefixUrl)
    {
        $em = $this->client->getContainer()->get('doctrine.orm.entity_manager');

        $user = $em
            ->getRepository('WallabagUserBundle:User')
            ->findOneByUserName('admin');
        $entry = $em
            ->getRepository('WallabagCoreBundle:Entry')
            ->findOneByUsernameAndNotArchived('admin');

        $annotation = new Annotation($user);
        $annotation->setEntry($entry);
        $annotation->setText('This is my annotation /o/');
        $annotation->setQuote('content');

        $em->persist($annotation);
        $em->flush();

        if ('annotations' === $prefixUrl) {
            $this->logInAs('admin');
        }

        $this->client->request('GET', $prefixUrl.'/'.$entry->getId().'.json');
        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());

        $content = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertGreaterThanOrEqual(1, $content['total']);
        $this->assertEquals($annotation->getText(), $content['rows'][0]['text']);

        // we need to re-fetch the annotation becase after the flush, it has been "detached" from the entity manager
        $annotation = $em->getRepository('WallabagAnnotationBundle:Annotation')->findAnnotationById($annotation->getId());
        $em->remove($annotation);
        $em->flush();
    }

    /**
     * Test creating an annotation for an entry.
     *
     * @dataProvider dataForEachAnnotations
     */
    public function testSetAnnotation($prefixUrl)
    {
        $em = $this->client->getContainer()->get('doctrine.orm.entity_manager');

        if ('annotations' === $prefixUrl) {
            $this->logInAs('admin');
        }

        /** @var Entry $entry */
        $entry = $em
            ->getRepository('WallabagCoreBundle:Entry')
            ->findOneByUsernameAndNotArchived('admin');

        $headers = ['CONTENT_TYPE' => 'application/json'];
        $content = json_encode([
            'text' => 'my annotation',
            'quote' => 'my quote',
            'ranges' => ['start' => '', 'startOffset' => 24, 'end' => '', 'endOffset' => 31],
        ]);
        $this->client->request('POST', $prefixUrl.'/'.$entry->getId().'.json', [], [], $headers, $content);

        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());

        $content = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertEquals('Big boss', $content['user']);
        $this->assertEquals('v1.0', $content['annotator_schema_version']);
        $this->assertEquals('my annotation', $content['text']);
        $this->assertEquals('my quote', $content['quote']);

        /** @var Annotation $annotation */
        $annotation = $this->client->getContainer()
            ->get('doctrine.orm.entity_manager')
            ->getRepository('WallabagAnnotationBundle:Annotation')
            ->findLastAnnotationByPageId($entry->getId(), 1);

        $this->assertEquals('my annotation', $annotation->getText());
    }

    /**
     * Test editing an existing annotation.
     *
     * @dataProvider dataForEachAnnotations
     */
    public function testEditAnnotation($prefixUrl)
    {
        $em = $this->client->getContainer()->get('doctrine.orm.entity_manager');

        $user = $em
            ->getRepository('WallabagUserBundle:User')
            ->findOneByUserName('admin');
        $entry = $em
            ->getRepository('WallabagCoreBundle:Entry')
            ->findOneByUsernameAndNotArchived('admin');

        $annotation = new Annotation($user);
        $annotation->setEntry($entry);
        $annotation->setText('This is my annotation /o/');
        $annotation->setQuote('my quote');

        $em->persist($annotation);
        $em->flush();

        $headers = ['CONTENT_TYPE' => 'application/json'];
        $content = json_encode([
            'text' => 'a modified annotation',
        ]);
        $this->client->request('PUT', $prefixUrl.'/'.$annotation->getId().'.json', [], [], $headers, $content);
        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());

        $content = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertEquals('Big boss', $content['user']);
        $this->assertEquals('v1.0', $content['annotator_schema_version']);
        $this->assertEquals('a modified annotation', $content['text']);
        $this->assertEquals('my quote', $content['quote']);

        /** @var Annotation $annotationUpdated */
        $annotationUpdated = $em
            ->getRepository('WallabagAnnotationBundle:Annotation')
            ->findOneById($annotation->getId());
        $this->assertEquals('a modified annotation', $annotationUpdated->getText());

        $em->remove($annotationUpdated);
        $em->flush();
    }

    /**
     * Test deleting an annotation.
     *
     * @dataProvider dataForEachAnnotations
     */
    public function testDeleteAnnotation($prefixUrl)
    {
        $em = $this->client->getContainer()->get('doctrine.orm.entity_manager');

        $user = $em
            ->getRepository('WallabagUserBundle:User')
            ->findOneByUserName('admin');
        $entry = $em
            ->getRepository('WallabagCoreBundle:Entry')
            ->findOneByUsernameAndNotArchived('admin');

        $annotation = new Annotation($user);
        $annotation->setEntry($entry);
        $annotation->setText('This is my annotation /o/');
        $annotation->setQuote('my quote');

        $em->persist($annotation);
        $em->flush();

        if ('annotations' === $prefixUrl) {
            $this->logInAs('admin');
        }

        $headers = ['CONTENT_TYPE' => 'application/json'];
        $content = json_encode([
            'text' => 'a modified annotation',
        ]);
        $this->client->request('DELETE', $prefixUrl.'/'.$annotation->getId().'.json', [], [], $headers, $content);
        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());

        $content = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertEquals('This is my annotation /o/', $content['text']);

        $annotationDeleted = $em
            ->getRepository('WallabagAnnotationBundle:Annotation')
            ->findOneById($annotation->getId());

        $this->assertNull($annotationDeleted);
    }
}
