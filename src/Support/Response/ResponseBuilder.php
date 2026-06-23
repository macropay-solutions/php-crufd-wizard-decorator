<?php

namespace MacropaySolutions\CrufdWizardDecorator\Support\Response;

use Illuminate\Http\JsonResponse;
use MacropaySolutions\CrufdWizard\Helpers\GeneralHelper;
use MacropaySolutions\CrufdWizardDecorator\Helpers\LogHelper;

class ResponseBuilder
{
    public const THE_GIVEN_DATA_WAS_INVALID = 'The given data was invalid.';

    protected bool $success = false;

    protected int $code = 500;

    protected string $lang = 'en';

    protected string $message;

    protected mixed $data = null;

    protected array $headers = [];

    public function respondSuccess(mixed $data = null, int $code = 200, ?string $message = null): JsonResponse
    {
        return $this->respond(true, $message, $data, $code);
    }

    public function respondError(string $message, mixed $data = null, int $code = 500): JsonResponse
    {
        return $this->respond(false, $message, $data, $code);
    }

    protected function buildResponse(): JsonResponse
    {
        try {
            if ($this->message === self::THE_GIVEN_DATA_WAS_INVALID) {
                $this->message = \substr_replace($this->message, ':', -1);

                foreach (($this->data ?? []) as $errorMessages) {
                    foreach ($errorMessages as $errorMessage) {
                        $this->message .= ' ' . $errorMessage;
                    }
                }
            }
        } catch (\Throwable $e) {
            LogHelper::logError($e, 'build error response');
        }

        return GeneralHelper::app(JsonResponse::class, [
            'data' => [
                'success' => $this->success,
                'code' => $this->code,
                'locale' => $this->lang,
                'message' => $this->message,
                'data' => $this->data
            ],
            'status' => 200,
            'headers' => $this->headers,
        ]);
    }

    private function respond(bool $success, ?string $message, mixed $data, int $code): JsonResponse
    {
        $this->success = $success;
        $this->data = $data;
        $this->code = $code;
        $this->message = $message ?? 'success';

        return $this->buildResponse();
    }

    public function withHeaders(array $headers): ResponseBuilder
    {
        $this->headers = $headers;

        return $this;
    }
}
