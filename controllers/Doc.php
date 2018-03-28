<?php

namespace MODXDocs\Controllers;

use DirectoryIterator;
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Environment;
use MODXDocs\Helpers\LinkRenderer;
use Webuni\CommonMark\TableExtension\TableExtension;
use Slim\Exception\NotFoundException;
use Slim\Http\Request;
use Slim\Http\Response;
use Spatie\YamlFrontMatter\YamlFrontMatter;
use TOC\MarkupFixer;
use TOC\TocGenerator;

class Doc extends Base
{
    protected $basePath;
    protected $baseUri;
    protected $file;
    protected $version;
    protected $language;
    protected $docPath;

    public function setVersion($version)
    {
        $path = $this->container->get('settings')['docSources'] . '/' . $version . '/';
        if (!file_exists($path) || !is_dir($path)) {
            throw new NotFoundException($this->request, $this->response);
        }
        $this->version = $version;
        $this->setVariable('version', $version);
        $this->basePath = $path;
        $this->baseUri = $this->container->get('settings')['directory'] . $version;
    }

    public function setLanguage($language)
    {
        $path = $this->basePath . $language . '/';
        if (!file_exists($path) || !is_dir($path)) {
            throw new NotFoundException($this->request, $this->response);
        }
        $this->setVariable('language', $language);
        $this->language = $language;
        $this->basePath .= $language . '/';
        $this->baseUri .= '/' . $language . '/';
    }

    public function setDocPath($path)
    {
        if (is_array($path)) {
            $path = implode('/', $path);
        }
        $path = rtrim($path, '/');
        $file = $path . '.md';
        if (!file_exists($this->basePath . $file)) {
            // See if we have an index file instead
            $file = $path . '/index.md';

            if (!file_exists($this->basePath . $file)) {
                throw new NotFoundException($this->request, $this->response);
            }
        }
        $this->docPath = $path;
        $this->file = $file;
    }

    public function initialize(Request $request, Response $response, array $args = array())
    {
        parent::initialize($request, $response, $args);
        $this->setVersion($request->getAttribute('version'));
        $this->setLanguage($request->getAttribute('language'));
        $this->setDocPath($request->getAttribute('path'));
        $this->getVersions();
        $this->getNavigation();
        return true;
    }

    public function home(Request $request, Response $response, array $args = array())
    {
        $request = $request
            ->withAttribute('version', 'current')
            ->withAttribute('language', 'en')
            ->withAttribute('path', '/');

        return $this->get($request, $response, $args);
    }

    public function get(Request $request, Response $response, array $args = array())
    {
        $init = $this->initialize($request, $response, $args);
        if ($init !== true) {
            return $init;
        }

        $fileContents = file_get_contents($this->basePath . $this->file);

        // Parse the front matter and make it available as meta
        $obj = YamlFrontMatter::parse($fileContents);
        $data = $obj->matter();
        $this->setVariable('meta', $data);

        // Generate a page title crumbs kinda thing
        $path = $request->getAttribute('path');
        $path = str_replace('-', ' ', $path);
        $path = explode('/', $path);
        $path = array_map('ucfirst', $path);
        $path = array_reverse($path);
        $path = implode(' / ', $path);
        $this->setVariable('page_title', $path);

        // Process the content
        $content = $obj->body();

        // Grab the markdown
        $environment = Environment::createCommonMarkEnvironment();
        $environment->addExtension(new TableExtension());
        $environment->addInlineRenderer('League\CommonMark\Inline\Element\Link',
            new LinkRenderer($this->baseUri)
        );
        $markdown = new CommonMarkConverter([
            'html_input' => 'allow',
        ], $environment);
        $content = $markdown->convertToHtml($content);

        $fixer = new MarkupFixer();
        $content = $fixer->fix($content);
        $this->setVariable('parsed', $content);

        // Generate table of contents
        $tocGenerator = new TocGenerator();
        $toc = $tocGenerator->getHtmlMenu($content, 2);
        $this->setVariable('toc', $toc);


        return $this->render('documentation.twig');
    }

    public function getVersions()
    {
        $dir = new DirectoryIterator($this->container->get('settings')['docSources']);

        $versions = [];
        foreach ($dir as $fileinfo) {
            if (!$fileinfo->isDir() || $fileinfo->isDot()) {
                continue;
            }

            $file = $fileinfo->getPathname() . '/' . $this->language . '/' . $this->docPath;
            $file = rtrim($file, '/');
            if (file_exists($file . '.md') || file_exists($file . '/index.md')) {
                $versions[] = [
                    'title' => $fileinfo->getFilename(),
                    'uri' => '/' . $fileinfo->getFilename() . '/' . $this->language . '/' . $this->docPath,
                ];
            }
        }

        $this->setVariable('versions', $versions);
    }

    public function getNavigation()
    {
        $nav = $this->getNavigationForParent($this->basePath);

        $out = $this->container->view->fetch('partials/nav.twig', ['children' => $nav]);

        $this->setVariable('nav', $out);
    }

    public function getNavigationForParent($path, $level = 1)
    {
        $nav = [];
        $dir = new DirectoryIterator($path);
        foreach ($dir as $file) {
            if ($file->isDot()) {
                continue;
            }

            $filePath = $file->getPathname();
            $filePath = strpos($filePath, '.md') !== false ? substr($filePath, 0, strpos($filePath, '.md')) : $filePath;
            $relativeFilePath = str_replace($this->basePath, '', $filePath);

            if ($file->isFile()) {
                if ($file->getFilename() === 'index.md') {
                    continue;
                }
                $current = [
                    'title' => $this->getTitle($file),
                    'uri' => $this->container->router->pathFor('documentation', [
                        'language' => $this->language,
                        'version' => $this->version,
                        'path' => $relativeFilePath,
                    ]),
                    'classes' => 'item',
                    'level' => $level,
                ];

                if (strpos($this->file, $relativeFilePath) !== false) {
                    $current['classes'] .= ' active';
                }

                if (is_dir($filePath . '/')) {
                    $current['classes'] .= ' has-children';
                    $current['children'] = $this->getNavigationForParent($filePath . '/', $level + 1);
                }
                $nav[] = $current;
            }

            // We handle directories slightly differently
            elseif ($file->isDir()) {
                if (file_exists($file->getPathname() . '/index.md')) {
                    $indexFile = new \SplFileInfo($file->getPathname() . '/index.md');
                    $current = [
                        'title' => $this->getTitle($indexFile),
                        'uri' => $this->container->router->pathFor('documentation', [
                            'language' => $this->language,
                            'version' => $this->version,
                            'path' => $relativeFilePath,
                        ]),
                        'classes' => 'item has-children',
                        'level' => $level,
                    ];
                    if (strpos($this->file, $relativeFilePath) !== false) {
                        $current['classes'] .= ' active';
                    }
                    $current['children'] = $this->getNavigationForParent($file->getPathname(), $level + 1);
                    $nav[] = $current;
                }
            }

        }

        return $nav;
    }

    private function getTitle(\SplFileInfo $file)
    {
        // Parse the front matter from the file
        $fileContents = $file->isFile() ? file_get_contents($file->getPathname()) : false;
//        if (!$fileContents && file_exists($file->getPathname() . '/index.md')) {
//            $fileContents = file_get_contents($file->getPathname() . '/index.md');
//        }

        $obj = YamlFrontMatter::parse($fileContents);
        $title = $obj->matter('title');
        if (empty($title)) {
            $name = $file->getFilename();
            $title = strpos($name, '.md') !== false ? substr($name, 0, strpos($name, '.md')) : $name;
            $title = implode(' ', explode('-', $title));
        }
        return $title;
    }
}