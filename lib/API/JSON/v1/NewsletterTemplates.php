<?php

namespace MailPoet\API\JSON\v1;

use MailPoet\API\JSON\Endpoint as APIEndpoint;
use MailPoet\API\JSON\Error as APIError;
use MailPoet\API\JSON\ResponseBuilders\NewsletterTemplatesResponseBuilder;
use MailPoet\Config\AccessControl;
use MailPoet\NewsletterTemplates\NewsletterTemplatesRepository;
use MailPoet\WP\Functions as WPFunctions;

class NewsletterTemplates extends APIEndpoint {
  public $permissions = [
    'global' => AccessControl::PERMISSION_MANAGE_EMAILS,
  ];

  protected static $getMethods = [
    'getAll',
  ];

  /** @var NewsletterTemplatesRepository */
  private $newsletterTemplatesRepository;

  /** @var NewsletterTemplatesResponseBuilder */
  private $newsletterTemplatesResponseBuilder;

  public function __construct(
    NewsletterTemplatesRepository $newsletterTemplatesRepository,
    NewsletterTemplatesResponseBuilder $newsletterTemplatesResponseBuilder
  ) {
    $this->newsletterTemplatesRepository = $newsletterTemplatesRepository;
    $this->newsletterTemplatesResponseBuilder = $newsletterTemplatesResponseBuilder;
  }

  public function get($data = []) {
    $template = isset($data['id'])
      ? $this->newsletterTemplatesRepository->findOneById((int)$data['id'])
      : null;

    if (!$template) {
      return $this->errorResponse([
        APIError::NOT_FOUND => WPFunctions::get()->__('This template does not exist.', 'mailpoet'),
      ]);
    }

    $data = $this->newsletterTemplatesResponseBuilder->build($template);
    return $this->successResponse($data);
  }

  public function getAll() {
    $templates = $this->newsletterTemplatesRepository->findAllForListing();
    $data = $this->newsletterTemplatesResponseBuilder->buildForListing($templates);
    return $this->successResponse($data);
  }

  public function save($data = []) {
    ignore_user_abort(true);
    try {
      $template = $this->newsletterTemplatesRepository->createOrUpdate($data);
      if (!empty($data['categories']) && $data['categories'] === NewsletterTemplatesRepository::RECENTLY_SENT_CATEGORIES) {
        $this->newsletterTemplatesRepository->cleanRecentlySent();
      }
      $data = $this->newsletterTemplatesResponseBuilder->build($template);
      return $this->successResponse($data);
    } catch (\Throwable $e) {
      return $this->errorResponse();
    }
  }

  public function delete($data = []) {
    $template = isset($data['id'])
      ? $this->newsletterTemplatesRepository->findOneById((int)$data['id'])
      : null;

    if (!$template) {
      return $this->errorResponse([
        APIError::NOT_FOUND => WPFunctions::get()->__('This template does not exist.', 'mailpoet'),
      ]);
    }

    $this->newsletterTemplatesRepository->remove($template);
    $this->newsletterTemplatesRepository->flush();
    return $this->successResponse(null, ['count' => 1]);
  }
}
