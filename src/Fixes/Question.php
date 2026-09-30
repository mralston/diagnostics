<?php

namespace Mralston\Diagnostics\Fixes;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;

/**
 * One thing a fix needs to ask before it can run. Built fluently, or from a
 * plain array with the same keys, and sent to the panel as an array.
 *
 *     Question::number('unit_rate', 'Unit rate on the bill')->suffix('p/kWh')->rules('required|numeric|min:1')
 *     ['name' => 'unit_rate', 'type' => 'number', 'label' => 'Unit rate on the bill', 'rules' => 'required|numeric|min:1']
 */
final class Question implements Arrayable
{
    public const TYPES = ['text', 'textarea', 'number', 'select', 'radio', 'checkbox', 'date', 'email'];

    private ?string $help = null;

    private mixed $default = null;

    /** @var array<string|int, string> value => label */
    private array $options = [];

    private array|string $rules = [];

    private ?string $placeholder = null;

    private ?string $suffix = null;

    private function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $label,
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown question type [{$type}]. Use one of: ".implode(', ', self::TYPES).'.');
        }

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException("Question names must be letters, digits and underscores; [{$name}] is not.");
        }
    }

    public static function make(string $type, string $name, string $label): self
    {
        return new self($name, $type, $label);
    }

    public static function text(string $name, string $label): self
    {
        return new self($name, 'text', $label);
    }

    public static function textarea(string $name, string $label): self
    {
        return new self($name, 'textarea', $label);
    }

    public static function number(string $name, string $label): self
    {
        return new self($name, 'number', $label);
    }

    /** @param array<string|int, string> $options value => label */
    public static function select(string $name, string $label, array $options): self
    {
        return (new self($name, 'select', $label))->options($options);
    }

    /** @param array<string|int, string> $options value => label */
    public static function radio(string $name, string $label, array $options): self
    {
        return (new self($name, 'radio', $label))->options($options);
    }

    public static function checkbox(string $name, string $label): self
    {
        return new self($name, 'checkbox', $label);
    }

    public static function date(string $name, string $label): self
    {
        return new self($name, 'date', $label);
    }

    public static function email(string $name, string $label): self
    {
        return new self($name, 'email', $label);
    }

    public static function fromArray(array $question): self
    {
        foreach (['name', 'label'] as $key) {
            if (empty($question[$key])) {
                throw new InvalidArgumentException("A fix question needs a [{$key}].");
            }
        }

        $made = new self($question['name'], $question['type'] ?? 'text', $question['label']);

        foreach (['help', 'default', 'options', 'rules', 'placeholder', 'suffix'] as $key) {
            if (array_key_exists($key, $question)) {
                $made->{$key}($question[$key]);
            }
        }

        return $made;
    }

    public static function normalise(self|array $question): self
    {
        return $question instanceof self ? $question : self::fromArray($question);
    }

    public function help(?string $help): self
    {
        $this->help = $help;

        return $this;
    }

    public function default(mixed $value): self
    {
        $this->default = $value;

        return $this;
    }

    /** @param array<string|int, string> $options value => label */
    public function options(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function rules(array|string $rules): self
    {
        $this->rules = $rules;

        return $this;
    }

    public function required(): self
    {
        $rules = is_string($this->rules) ? array_filter(explode('|', $this->rules)) : $this->rules;
        $this->rules = array_merge(['required'], array_values(array_diff($rules, ['required', 'nullable'])));

        return $this;
    }

    public function placeholder(?string $placeholder): self
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    /** A unit shown after the input, such as "p/kWh". */
    public function suffix(?string $suffix): self
    {
        $this->suffix = $suffix;

        return $this;
    }

    /**
     * Validation rules for the answer. Choices are always limited to the
     * options offered, and an unanswered question is null unless required.
     */
    public function validationRules(): array
    {
        $rules = is_string($this->rules) ? array_values(array_filter(explode('|', $this->rules))) : $this->rules;

        if (! in_array('required', $rules, true) && ! in_array('nullable', $rules, true)) {
            array_unshift($rules, 'nullable');
        }

        $rules[] = match ($this->type) {
            'number' => 'numeric',
            'checkbox' => 'boolean',
            'date' => 'date',
            'email' => 'email',
            'select', 'radio' => 'in:'.implode(',', array_map('strval', array_keys($this->options))),
            default => 'string',
        };

        return array_values(array_unique($rules, SORT_REGULAR));
    }

    /** The answer as the fix should receive it: numbers as numbers, checkboxes as booleans. */
    public function cast(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $this->type === 'checkbox' ? false : null;
        }

        return match ($this->type) {
            'number' => str_contains((string) $value, '.') ? (float) $value : (int) $value,
            'checkbox' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }

    public function toArray(): array
    {
        $rules = $this->validationRules();

        return [
            'name' => $this->name,
            'type' => $this->type,
            'label' => $this->label,
            'help' => $this->help,
            'default' => $this->default,
            // An ordered list, so numeric option keys survive JSON and the order is kept.
            'options' => array_map(fn ($value, $label) => ['value' => (string) $value, 'label' => $label], array_keys($this->options), $this->options),
            'required' => in_array('required', $rules, true),
            'placeholder' => $this->placeholder,
            'suffix' => $this->suffix,
        ];
    }
}
