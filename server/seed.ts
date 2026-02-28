import { db } from "./db";
import { snippets } from "@shared/schema";
import { sql } from "drizzle-orm";

const seedSnippets = [
  {
    title: "String Manipulation",
    description: "Common PHP string functions and operations",
    code: `<?php

$text = "Hello, PHP World!";

echo "Original: $text\\n";
echo "Uppercase: " . strtoupper($text) . "\\n";
echo "Lowercase: " . strtolower($text) . "\\n";
echo "Length: " . strlen($text) . "\\n";
echo "Reversed: " . strrev($text) . "\\n";
echo "Word count: " . str_word_count($text) . "\\n";
echo "Replace: " . str_replace("World", "Developer", $text) . "\\n";
echo "Substring: " . substr($text, 0, 5) . "\\n";
echo "Position of 'PHP': " . strpos($text, "PHP") . "\\n";
`,
  },
  {
    title: "Array Functions",
    description: "Working with arrays: map, filter, reduce, and more",
    code: `<?php

$numbers = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

// Filter even numbers
$evens = array_filter($numbers, fn($n) => $n % 2 === 0);
echo "Even numbers: " . implode(", ", $evens) . "\\n";

// Map - square each number
$squared = array_map(fn($n) => $n * $n, $numbers);
echo "Squared: " . implode(", ", $squared) . "\\n";

// Reduce - sum all numbers
$sum = array_reduce($numbers, fn($carry, $n) => $carry + $n, 0);
echo "Sum: $sum\\n";

// Sort
$fruits = ["Banana", "Apple", "Cherry", "Date"];
sort($fruits);
echo "Sorted: " . implode(", ", $fruits) . "\\n";

// Associative array
$person = [
    "name" => "Alice",
    "age" => 30,
    "city" => "Portland"
];
echo "\\nPerson:\\n";
foreach ($person as $key => $value) {
    echo "  $key: $value\\n";
}
`,
  },
  {
    title: "OOP Basics",
    description: "Object-oriented programming with classes, interfaces, and traits",
    code: `<?php

interface Describable {
    public function describe(): string;
}

trait HasColor {
    private string $color;
    
    public function getColor(): string {
        return $this->color;
    }
    
    public function setColor(string $color): void {
        $this->color = $color;
    }
}

class Shape implements Describable {
    use HasColor;
    
    public function __construct(
        protected string $name,
        string $color = "red"
    ) {
        $this->color = $color;
    }
    
    public function describe(): string {
        return "{$this->name} ({$this->getColor()})";
    }
}

class Circle extends Shape {
    public function __construct(
        private float $radius,
        string $color = "blue"
    ) {
        parent::__construct("Circle", $color);
    }
    
    public function area(): float {
        return M_PI * $this->radius ** 2;
    }
    
    public function describe(): string {
        return parent::describe() . " r={$this->radius}";
    }
}

$circle = new Circle(5, "green");
echo $circle->describe() . "\\n";
echo "Area: " . number_format($circle->area(), 2) . "\\n";
`,
  },
  {
    title: "Date & Time",
    description: "Working with dates, times, and intervals in PHP",
    code: `<?php

$now = new DateTime();
echo "Current: " . $now->format("Y-m-d H:i:s") . "\\n";
echo "Day: " . $now->format("l") . "\\n";
echo "Month: " . $now->format("F") . "\\n\\n";

// Date arithmetic
$future = clone $now;
$future->modify("+30 days");
echo "30 days from now: " . $future->format("Y-m-d") . "\\n";

$past = clone $now;
$past->modify("-1 year");
echo "1 year ago: " . $past->format("Y-m-d") . "\\n\\n";

// Interval
$interval = $now->diff($past);
echo "Difference: {$interval->days} days\\n";

// Timezone
$tokyo = new DateTime("now", new DateTimeZone("Asia/Tokyo"));
echo "\\nTokyo: " . $tokyo->format("H:i:s T") . "\\n";
$london = new DateTime("now", new DateTimeZone("Europe/London"));
echo "London: " . $london->format("H:i:s T") . "\\n";
`,
  },
  {
    title: "Pattern Matching & Regex",
    description: "Regular expressions and pattern matching examples",
    code: `<?php

$text = "Contact us at info@example.com or support@test.org";

// Find all email addresses
preg_match_all('/[\\w.+-]+@[\\w-]+\\.[\\w.]+/', $text, $matches);
echo "Emails found:\\n";
foreach ($matches[0] as $email) {
    echo "  - $email\\n";
}

// Validate formats
$tests = [
    "2024-01-15" => '/^\\d{4}-\\d{2}-\\d{2}$/',
    "192.168.1.1" => '/^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$/',
    "+1-555-1234" => '/^\\+?\\d{1,3}-\\d{3}-\\d{4}$/',
];

echo "\\nValidation:\\n";
foreach ($tests as $value => $pattern) {
    $valid = preg_match($pattern, $value) ? "valid" : "invalid";
    echo "  $value => $valid\\n";
}

// Replace
$html = "<p>Hello <b>World</b></p>";
$stripped = preg_replace('/<[^>]+>/', '', $html);
echo "\\nStripped HTML: $stripped\\n";
`,
  },
];

export async function seed() {
  const existing = await db.select({ id: snippets.id }).from(snippets).limit(1);
  if (existing.length > 0) return;

  for (const snippet of seedSnippets) {
    await db.insert(snippets).values(snippet);
  }
  console.log("Seeded database with example snippets");
}
