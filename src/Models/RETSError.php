<?php namespace PHRETS\Models;

class RETSError extends \Exception
{
    protected $code;
    protected $message;

    /**
     * @param string $code
     * @return $this
     */
    public function setCode($code)
    {
        $this->code = (int)$code;
        return $this;
    }

    /**
     * @param string $message
     * @return $this
     */
    public function setMessage($message)
    {
        $this->message = $message;
        return $this;
    }
}
