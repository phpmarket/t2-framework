<?php

namespace T2\Http;

use T2\File;
use function pathinfo;

class UploadFile extends File
{
    /**
     * @var ?string
     */
    protected ?string $uploadName = null;

    /**
     * @var ?string
     */
    protected ?string $uploadMimeType = null;

    /**
     * @var ?int
     */
    protected ?int $uploadErrorCode = null;

    /**
     * UploadFile constructor.
     *
     * @param string $fileName
     * @param string $uploadName
     * @param string $uploadMimeType
     * @param int    $uploadErrorCode
     */
    public function __construct(string $fileName, string $uploadName, string $uploadMimeType, int $uploadErrorCode)
    {
        $this->uploadName = $uploadName;
        $this->uploadMimeType = $uploadMimeType;
        $this->uploadErrorCode = $uploadErrorCode;
        parent::__construct($fileName);
    }

    /**
     * GetUploadName
     *
     * @return ?string
     */
    public function getUploadName(): ?string
    {
        return $this->uploadName;
    }

    /**
     * GetUploadMimeType
     *
     * @return ?string
     */
    public function getUploadMimeType(): ?string
    {
        return $this->uploadMimeType;
    }

    /**
     * GetUploadExtension
     *
     * @return string
     */
    public function getUploadExtension(): string
    {
        return pathinfo($this->uploadName, PATHINFO_EXTENSION);
    }

    /**
     * GetUploadErrorCode
     *
     * @return ?int
     */
    public function getUploadErrorCode(): ?int
    {
        return $this->uploadErrorCode;
    }

    /**
     * IsValid
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->uploadErrorCode === UPLOAD_ERR_OK;
    }

    /**
     * GetUploadMineType
     *
     * @return ?string
     * @deprecated
     */
    public function getUploadMineType(): ?string
    {
        return $this->uploadMimeType;
    }
}
