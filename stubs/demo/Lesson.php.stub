<?php

namespace App\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;
use NativeBlade\Facades\NativeBlade;

#[Layout('components.layouts.app')]
class Lesson extends Component
{
    public int $lessonId;
    public string $lessonTitle = '';
    public int $currentQuestion = 0;
    public int $totalQuestions = 0;
    public int $score = 0;
    public ?string $selected = null;
    public ?string $correct = null;
    public bool $answered = false;
    public bool $finished = false;
    public array $questions = [];

    private static array $allLessons = [
        1 => [
            'title' => 'Basics',
            'questions' => [
                ['q' => 'What does "Hello" mean in Portuguese?', 'options' => ['Olá', 'Tchau', 'Obrigado', 'Por favor'], 'answer' => 'Olá'],
                ['q' => 'How do you say "Thank you"?', 'options' => ['Desculpa', 'Obrigado', 'Olá', 'Sim'], 'answer' => 'Obrigado'],
                ['q' => 'What is "Yes" in Portuguese?', 'options' => ['Não', 'Talvez', 'Sim', 'Nunca'], 'answer' => 'Sim'],
            ],
        ],
        2 => [
            'title' => 'Greetings',
            'questions' => [
                ['q' => 'How do you say "Good morning"?', 'options' => ['Boa noite', 'Boa tarde', 'Bom dia', 'Oi'], 'answer' => 'Bom dia'],
                ['q' => 'What means "See you later"?', 'options' => ['Até logo', 'Bom dia', 'Obrigado', 'Olá'], 'answer' => 'Até logo'],
                ['q' => '"Como vai?" means...', 'options' => ['Goodbye', 'How are you?', 'Thank you', 'Please'], 'answer' => 'How are you?'],
            ],
        ],
        3 => [
            'title' => 'Food',
            'questions' => [
                ['q' => 'What is "Water" in Portuguese?', 'options' => ['Leite', 'Suco', 'Água', 'Café'], 'answer' => 'Água'],
                ['q' => 'How do you say "Bread"?', 'options' => ['Arroz', 'Pão', 'Carne', 'Fruta'], 'answer' => 'Pão'],
                ['q' => '"Café" means...', 'options' => ['Tea', 'Juice', 'Coffee', 'Milk'], 'answer' => 'Coffee'],
            ],
        ],
        4 => [
            'title' => 'Travel',
            'questions' => [
                ['q' => 'How do you say "Airport"?', 'options' => ['Estação', 'Aeroporto', 'Hotel', 'Praia'], 'answer' => 'Aeroporto'],
                ['q' => 'What is "Beach" in Portuguese?', 'options' => ['Montanha', 'Cidade', 'Praia', 'Rio'], 'answer' => 'Praia'],
                ['q' => '"Passaporte" means...', 'options' => ['Ticket', 'Passport', 'Luggage', 'Map'], 'answer' => 'Passport'],
            ],
        ],
        5 => [
            'title' => 'Family',
            'questions' => [
                ['q' => 'How do you say "Mother"?', 'options' => ['Pai', 'Irmã', 'Mãe', 'Avó'], 'answer' => 'Mãe'],
                ['q' => 'What is "Brother" in Portuguese?', 'options' => ['Primo', 'Irmão', 'Tio', 'Filho'], 'answer' => 'Irmão'],
                ['q' => '"Avô" means...', 'options' => ['Uncle', 'Father', 'Grandfather', 'Cousin'], 'answer' => 'Grandfather'],
            ],
        ],
        6 => [
            'title' => 'Numbers',
            'questions' => [
                ['q' => 'What is "Three" in Portuguese?', 'options' => ['Dois', 'Três', 'Quatro', 'Cinco'], 'answer' => 'Três'],
                ['q' => 'How do you say "Ten"?', 'options' => ['Oito', 'Nove', 'Dez', 'Sete'], 'answer' => 'Dez'],
                ['q' => '"Cem" means...', 'options' => ['Ten', 'Fifty', 'Thousand', 'Hundred'], 'answer' => 'Hundred'],
            ],
        ],
        7 => [
            'title' => 'Grammar',
            'questions' => [
                ['q' => '"Eu sou" means...', 'options' => ['I have', 'I am', 'I go', 'I want'], 'answer' => 'I am'],
                ['q' => 'How do you say "They are"?', 'options' => ['Eles são', 'Nós somos', 'Vocês têm', 'Eu sou'], 'answer' => 'Eles são'],
                ['q' => '"Nós temos" means...', 'options' => ['We go', 'We are', 'We have', 'We want'], 'answer' => 'We have'],
            ],
        ],
    ];

    public function mount(int $id)
    {
        $this->lessonId = $id;
        $lesson = self::$allLessons[$id] ?? self::$allLessons[1];
        $this->lessonTitle = $lesson['title'];
        $this->questions = $lesson['questions'];
        $this->totalQuestions = count($this->questions);
    }

    public function select(string $option)
    {
        if ($this->answered) return;
        $this->selected = $option;
    }

    public function check()
    {
        if ($this->answered || !$this->selected) return;

        $this->correct = $this->questions[$this->currentQuestion]['answer'];
        $this->answered = true;

        if ($this->selected === $this->correct) {
            $this->score++;
        }
    }

    public function next()
    {
        if ($this->currentQuestion + 1 >= $this->totalQuestions) {
            $this->finished = true;

            $xp = NativeBlade::getState('trail.xp', 0);
            NativeBlade::setState('trail.xp', $xp + ($this->score * 10));

            if ($this->score === $this->totalQuestions) {
                $completed = NativeBlade::getState('trail.completed', []);
                if (!in_array($this->lessonId, $completed)) {
                    $completed[] = $this->lessonId;
                    NativeBlade::setState('trail.completed', $completed);
                }
                $streak = NativeBlade::getState('trail.streak', 0);
                NativeBlade::setState('trail.streak', $streak + 1);
            }
            return;
        }

        $this->currentQuestion++;
        $this->selected = null;
        $this->correct = null;
        $this->answered = false;
    }

    public function render()
    {
        return view('livewire.lesson');
    }
}
