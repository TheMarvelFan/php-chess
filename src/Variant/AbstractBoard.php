<?php

namespace Chess\Variant;

use Chess\FenToBoardFactory;
use Chess\Eval\SpaceEval;
use Chess\Eval\SqCount;
use Chess\Variant\Classical\PGN\AN\Castle;
use Chess\Variant\Classical\PGN\AN\Color;
use Chess\Variant\Classical\PGN\AN\Piece;
use Chess\Variant\Classical\Piece\B;
use Chess\Variant\Classical\Piece\K;
use Chess\Variant\Classical\Piece\N;
use Chess\Variant\Classical\Piece\P;
use Chess\Variant\Classical\Piece\Q;
use Chess\Variant\Classical\Piece\R;
use Chess\Variant\Classical\PGN\Move;

abstract class AbstractBoard extends \SplObjectStorage
{
    use AbstractBoardObserverTrait;

    /**
     * Current player's turn.
     *
     * @var string
     */
    public string $turn = '';

    /**
     * Captured pieces.
     *
     * @var array
     */
    public array $captures = [
        Color::W => [],
        Color::B => [],
    ];

    /**
     * History.
     *
     * @var array
     */
    public array $history = [];

    /**
     * Color.
     *
     * @var \Chess\Variant\AbstractNotation
     */
    public AbstractNotation $color;

    /**
     * Castling rule.
     *
     * @var \Chess\Variant\AbstractNotation
     */
    public ?AbstractNotation $castlingRule = null;

    /**
     * Square.
     *
     * @var \Chess\Variant\AbstractNotation
     */
    public AbstractNotation $square;

    /**
     * Move.
     *
     * @var \Chess\Variant\AbstractNotation
     */
    public AbstractNotation $move;

    /**
     * Castling ability.
     *
     * @var string
     */
    public string $castlingAbility = '-';

    /**
     * Piece variant.
     *
     * @var string
     */
    public string $pieceVariant = '';

    /**
     * Start FEN position.
     *
     * @var string
     */
    public string $startFen = '';

    /**
     * Space evaluation.
     *
     * @var array
     */
    public array $spaceEval;

    /**
     * Count squares.
     *
     * @var array
     */
    public array $sqCount;

    /**
     * Picks a piece from the board.
     *
     * @param array $move
     * @return array
     */
    protected function pickPiece(array $move): array
    {
        $pieces = [];
        foreach ($this->pieces($move['color']) as $piece) {
            if ($piece->id === $move['id']) {
                if (strstr($piece->sq, $move['sq']['current'])) {
                    $piece->move = $move;
                    $pieces[] = $piece;
                }
            }
        }

        return $pieces;
    }

    /**
     * Returns true if the move is syntactically valid.
     *
     * @param array $move
     * @return bool
     */
    protected function isValidMove(array $move): bool
    {
        if ($this->isAmbiguousCapture($move)) {
            return false;
        } elseif ($this->isAmbiguousMove($move)) {
            return false;
        }

        return true;
    }

    /**
     * Returns true if the capture move is ambiguous.
     *
     * @param array $move
     * @return bool
     */
    protected function isAmbiguousCapture(array $move): bool
    {
        if ($move['isCapture']) {
            if ($move['id'] === Piece::P) {
                $enPassant = $this->history
                    ? $this->enPassant()
                    : explode(' ', $this->startFen)[3];
                if (!$this->pieceBySq($move['sq']['next']) && $enPassant !== $move['sq']['next']) {
                    return true;
                }
            } else {
                if (!$this->pieceBySq($move['sq']['next'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Returns true if the move is ambiguous.
     *
     * @param array $move
     * @return bool
     */
    protected function isAmbiguousMove(array $move): bool
    {
        $ambiguous = [];
        foreach ($this->pickPiece($move) as $piece) {
            if (in_array($move['sq']['next'], $piece->moveSqs())) {
                if (!$this->isPinned($piece)) {
                    $ambiguous[] = $move['sq']['next'];
                }
            }
        }

        return count($ambiguous) > 1;
    }

    /**
     * Returns true if the move is legal.
     *
     * @param array $move
     * @return bool
     */
    protected function isLegalMove(array $move): bool
    {
        foreach ($pieces = $this->pickPiece($move) as $piece) {
            if ($piece->isMovable()) {
                if (!$this->isPinned($piece)) {
                    if ($piece->move['type'] === $this->move->case(Move::CASTLE_SHORT)) {
                        return $this->castle($piece, RType::CASTLE_SHORT);
                    } elseif ($piece->move['type'] === $this->move->case(Move::CASTLE_LONG)) {
                        return $this->castle($piece, RType::CASTLE_LONG);
                    } else {
                        return $this->move($piece);
                    }
                }
            }
        }

        return false;
    }

    /**
     * Makes a move.
     *
     * @param \Chess\Variant\AbstractPiece $piece
     * @return bool
     */
    protected function move(AbstractPiece $piece): bool
    {
        if ($piece->move['isCapture']) {
            $this->capture($piece);
        }
        $this->detach($this->pieceBySq($piece->sq));
        $class = VariantType::getClass($this->pieceVariant, $piece->id);
        $this->attach(new $class(
            $piece->color,
            $piece->move['sq']['next'],
            $this->square,
            $piece->id === Piece::R ? $piece->type : null
        ));
        if ($piece->id === Piece::P) {
            if ($piece->isPromoted()) {
                $this->promote($piece);
            }
        }
        $this->updateCastle($piece)->pushHistory($piece)->refresh();

        return true;
    }

    /**
     * Castles the king.
     *
     * @param \Chess\Variant\Classical\Piece\K $king
     * @param string $rookType
     * @return bool
     */
    protected function castle(K $king, string $rookType): bool
    {
        if ($rook = $king->getCastleRook($rookType)) {
            $this->detach($this->pieceBySq($king->sq));
            $this->attach(
                new K(
                    $king->color,
                    $this->castlingRule->rule[$king->color][Piece::K][rtrim($king->move['pgn'], '+')]['sq']['next'],
                    $this->square
                )
             );
            $this->detach($rook);
            $this->attach(
                new R(
                    $rook->color,
                    $this->castlingRule->rule[$king->color][Piece::R][rtrim($king->move['pgn'], '+')]['sq']['next'],
                    $this->square,
                    $rook->type
                )
            );
            $this->castlingAbility = $this->castlingRule->castle($this->castlingAbility, $this->turn);
            $this->pushHistory($king)->refresh();
            return true;
        }

        return false;
    }

    /**
     * Updates the castle property.
     *
     * @param \Chess\Variant\AbstractPiece $piece
     * @return \Chess\Variant\AbstractBoard
     */
    protected function updateCastle(AbstractPiece $piece): AbstractBoard
    {
        if ($this->castlingRule?->can($this->castlingAbility, $this->turn)) {
            if ($piece->id === Piece::K) {
                $this->castlingAbility = $this->castlingRule->update(
                    $this->castlingAbility,
                    $this->turn,
                    [Piece::K, Piece::Q]
                );
            } elseif ($piece->id === Piece::R) {
                if ($piece->type === RType::CASTLE_SHORT) {
                    $this->castlingAbility = $this->castlingRule->update(
                        $this->castlingAbility,
                        $this->turn,
                        [Piece::K]
                    );
                } elseif ($piece->type === RType::CASTLE_LONG) {
                    $this->castlingAbility = $this->castlingRule->update(
                        $this->castlingAbility,
                        $this->turn,
                        [Piece::Q]
                    );
                }
            }
        }
        $oppColor = $this->color->opp($this->turn);
        if ($this->castlingRule?->can($this->castlingAbility, $oppColor)) {
            if ($piece->move['isCapture']) {
                if ($piece->move['sq']['next'] ===
                    $this->castlingRule->rule[$oppColor][Piece::R][Castle::SHORT]['sq']['current']
                ) {
                    $this->castlingAbility = $this->castlingRule->update(
                        $this->castlingAbility,
                        $oppColor,
                        [Piece::K]
                    );
                } elseif (
                    $piece->move['sq']['next'] ===
                    $this->castlingRule->rule[$oppColor][Piece::R][Castle::LONG]['sq']['current']
                ) {
                    $this->castlingAbility = $this->castlingRule->update(
                        $this->castlingAbility,
                        $oppColor,
                        [Piece::Q]
                    );
                }
            }
        }

        return $this;
    }

    /**
     * Captures a piece.
     *
     * @param \Chess\Variant\AbstractPiece $piece
     * @return \Chess\Variant\AbstractBoard
     */
    protected function capture(AbstractPiece $piece): AbstractBoard
    {
        if (
            $piece->id === Piece::P &&
            $piece->enPassantSq &&
            !$this->pieceBySq($piece->move['sq']['next'])
        ) {
            if ($captured = $piece->enPassantPawn()) {
                $capturedData = [
                    'id' => $captured->id,
                    'sq' => $captured->sq,
                ];
            }
        } elseif ($captured = $this->pieceBySq($piece->move['sq']['next'])) {
            $capturedData = [
                'id' => $captured->id,
                'sq' => $captured->sq,
            ];
        }
        if ($captured) {
            $capturingData = [
                'id' => $piece->id,
                'sq' => $piece->sq,
            ];
            $piece->id !== Piece::R ?: $capturingData['type'] = $piece->type;
            $captured->id !== Piece::R ?: $capturedData['type'] = $captured->type;
            $this->captures[$piece->color][] = [
                'capturing' => $capturingData,
                'captured' => $capturedData,
            ];
            $this->detach($captured);
        }

        return $this;
    }

    /**
     * Promotes a pawn.
     *
     * @param \Chess\Variant\Classical\Piece\P $pawn
     * @return \Chess\Variant\AbstractBoard
     */
    protected function promote(P $pawn): AbstractBoard
    {
        $this->detach($this->pieceBySq($pawn->move['sq']['next']));
        if ($pawn->move['newId'] === Piece::N) {
            $this->attach(new N(
                $pawn->color,
                $pawn->move['sq']['next'],
                $this->square
            ));
        } elseif ($pawn->move['newId'] === Piece::B) {
            $this->attach(new B(
                $pawn->color,
                $pawn->move['sq']['next'],
                $this->square
            ));
        } elseif ($pawn->move['newId'] === Piece::R) {
            $this->attach(new R(
                $pawn->color,
                $pawn->move['sq']['next'],
                $this->square,
                RType::R
            ));
        } else {
            $this->attach(new Q(
                $pawn->color,
                $pawn->move['sq']['next'],
                $this->square
            ));
        }

        return $this;
    }

    /**
     * Returns true if the piece is pinned.
     *
     * @param \Chess\Variant\AbstractPiece $piece
     * @return bool
     */
    protected function isPinned(AbstractPiece $piece): bool
    {
        $clone = $this->clone();
        if ($clone->move($piece)) {
            return !empty($clone->piece($piece->color, Piece::K)?->attacking());
        }

        return false;
    }

    /**
     * Adds a new element to the history.
     *
     * @param \Chess\Variant\AbstractPiece $piece
     * @return \Chess\Variant\AbstractBoard
     */
    protected function pushHistory(AbstractPiece $piece): AbstractBoard
    {
        $this->history[] = [
            'castlingAbility' => $this->castlingAbility,
            'sq' => $piece->sq,
            'move' => $piece->move,
        ];

        return $this;
    }

    /**
     * Removes an element from the history.
     *
     * @return \Chess\Variant\AbstractBoard
     */
    protected function popHistory(): AbstractBoard
    {
        array_pop($this->history);

        return $this;
    }

    /**
     * Converts a LAN move into an array of disambiguated PGN moves.
     *
     * @param string $color
     * @param string $lan
     * @return array
     */
    protected function lanToPgn(string $color, string $lan): array
    {
        $pgn = [];
        $sqs = $this->move->explodeSqs($lan);
        if (isset($sqs[0]) && isset($sqs[1])) {
            $a = $this->pieceBySq($sqs[0]);
            $b = $this->pieceBySq($sqs[1]);
            if ($a) {
                if ($a->id === Piece::K) {
                    if (
                        $this->castlingRule?->rule[$color][Piece::K][Castle::SHORT]['sq']['next'] === $sqs[1] &&
                        $a->sqCastleShort()
                    ) {
                        $pgn[] = Castle::SHORT;
                    } elseif (
                        $this->castlingRule?->rule[$color][Piece::K][Castle::LONG]['sq']['next'] === $sqs[1] &&
                        $a->sqCastleLong()
                    ) {
                        $pgn[] = Castle::LONG;
                    } elseif ($b) {
                        $pgn[] = "{$a->id}x{$sqs[1]}";
                    } else {
                        $pgn[] = "{$a->id}{$sqs[1]}";
                    }
                } elseif ($a->id === Piece::P) {
                    if ($b) {
                        $pgn[] = "{$a->file()}x{$sqs[1]}";
                    } elseif ($a->enPassantSq) {
                        $pgn[] = "{$a->file()}x{$a->enPassantSq}";
                    } else {
                        $pgn[] = $sqs[1];
                    }
                    $newId = mb_substr($lan, -1);
                    if (ctype_alpha($newId)) {
                        $pgn[0] .= '=' . mb_strtoupper($newId);
                    }
                } else {
                    if ($b) {
                        $pgn[] = "{$a->id}x{$sqs[1]}";
                        $pgn[] = "{$a->id}{$a->file()}x{$sqs[1]}";
                        $pgn[] = "{$a->id}{$a->rank()}x{$sqs[1]}";
                        $pgn[] = "{$a->id}{$a->sq}x{$sqs[1]}";
                    } else {
                        $pgn[] = "{$a->id}{$sqs[1]}";
                        $pgn[] = "{$a->id}{$a->file()}{$sqs[1]}";
                        $pgn[] = "{$a->id}{$a->rank()}{$sqs[1]}";
                        $pgn[] = "{$a->id}{$a->sq}{$sqs[1]}";
                    }
                }
            }
        }

        return $pgn;
    }

    /**
     * Updates the history after a LAN move has been played.
     *
     * @return bool
     */
    protected function afterPlayLan(): bool
    {
        if ($this->isMate()) {
            $this->history[count($this->history) - 1]['move']['pgn'] .= '#';
        } elseif ($this->isCheck()) {
            $this->history[count($this->history) - 1]['move']['pgn'] .= '+';
        }

        return true;
    }

    public function refresh(): void
    {
        $this->turn = $this->color->opp($this->turn);

        $this->sqCount = (new SqCount($this))->count();

        $this->detachPieces()
            ->attachPieces()
            ->notifyPieces();

        $this->spaceEval = (new SpaceEval($this))->getResult();

        $this->notifyPieces();

        if ($this->history) {
            $this->history[count($this->history) - 1]['fen'] = $this->toFen();
        }
    }

    public function movetext(): string
    {
        $movetext = '';
        foreach ($this->history as $key => $val) {
            if ($key === 0) {
                $movetext .= $val['move']['color'] === Color::W
                    ? "1.{$val['move']['pgn']}"
                    : '1' . Move::ELLIPSIS . "{$val['move']['pgn']} ";
            } else {
                if ($this->history[0]['move']['color'] === Color::W) {
                    $movetext .= $key % 2 === 0
                        ? ($key / 2 + 1) . ".{$val['move']['pgn']}"
                        : " {$val['move']['pgn']} ";
                } else {
                    $movetext .= $key % 2 === 0
                        ? " {$val['move']['pgn']} "
                        : (ceil($key / 2) + 1) . ".{$val['move']['pgn']}";
                }
            }
        }

        return trim($movetext);
    }

    public function piece(string $color, string $id): ?AbstractPiece
    {
        $this->rewind();
        while ($this->valid()) {
            $piece = $this->current();
            if ($piece->color === $color && $piece->id === $id) {
                return $piece;
            }
            $this->next();
        }

        return null;
    }

    public function pieces(string $color = ''): array
    {
        $pieces = [];
        $this->rewind();
        while ($this->valid()) {
            $piece = $this->current();
            if ($color) {
                if ($piece->color === $color) {
                    $pieces[] = $piece;
                }
            } else {
                $pieces[] = $piece;
            }
            $this->next();
        }

        return $pieces;
    }

    public function pieceBySq(string $sq): ?AbstractPiece
    {
        $this->rewind();
        while ($this->valid()) {
            $piece = $this->current();
            if ($piece->sq === $sq) {
                return $piece;
            }
            $this->next();
        }

        return null;
    }

    public function play(string $color, string $pgn): bool
    {
        $move = $this->move->toArray($color, $pgn, $this->castlingRule, $this->color);
        if ($this->isValidMove($move)) {
            return $this->isLegalMove($move);
        }

        return false;
    }

    public function playLan(string $color, string $lan): bool
    {
        if ($color === $this->turn) {
            foreach ($this->lanToPgn($color, $lan) as $val) {
                if ($this->play($color, $val)) {
                    return $this->afterPlayLan();
                }
            }
        }

        return false;
    }

    public function undo(): AbstractBoard
    {
        $board = FenToBoardFactory::create($this->startFen, $this);
        foreach ($this->popHistory()->history as $key => $val) {
            $board->play($val['move']['color'], $val['move']['pgn']);
        }

        return $board;
    }

    public function isCheck(): bool
    {
        if ($king = $this->piece($this->turn, Piece::K)) {
            return $king->attacking() !== [];
        }

        return false;
    }

    public function isMate(): bool
    {
        if ($king = $this->piece($this->turn, Piece::K)) {
            if ($attacking = $king->attacking()) {
                if (count($attacking) === 1) {
                    foreach ($attacking[0]->attacking() as $attackingAttacking) {
                        if (!$attackingAttacking->isPinned()) {
                            return false;
                        }
                    }
                    $moveSqs = [];
                    foreach ($this->pieces($this->turn) as $piece) {
                        if (!$piece->isPinned()) {
                            $moveSqs = [
                                ...$moveSqs,
                                ...$piece->moveSqs(),
                            ];
                        }
                    }
                    $lineOfAttack = $attacking[0]->lineOfAttack();
                    return $king->moveSqs() === [] &&
                        array_intersect($lineOfAttack, $moveSqs) === [];
                } elseif (count($attacking) > 1) {
                    return $king->moveSqs() === [];
                }
            }
        }

        return false;
    }

    public function isStalemate(): bool
    {
        if (!$this->piece($this->turn, Piece::K)->attacking()) {
            $moveSqs = [];
            foreach ($this->pieces($this->turn) as $piece) {
                if (!$piece->isPinned()) {
                    $moveSqs = [
                        ...$moveSqs,
                        ...$piece->moveSqs(),
                    ];
                }
            }
            return $moveSqs === [];
        }

        return false;
    }

    public function isFivefoldRepetition(): bool
    {
        $count = array_count_values(array_column($this->history, 'fen'));
        foreach ($count as $key => $val) {
            if ($val >= 5) {
                return true;
            }
        }

        return false;
    }

    public function isFiftyMoveDraw(): bool
    {
        return count($this->history) >= 100;

        foreach (array_reverse($this->history) as $key => $value) {
            if ($key < 100) {
                if ($value->move->isCapture) {
                    return  false;
                } elseif (
                    $value->move->type === $this->move->case(Move::PAWN) ||
                    $value->move->type === $this->move->case(Move::PAWN_PROMOTES)
                ) {
                    return  false;
                }
            }
        }

        return true;
    }

    public function isDeadPositionDraw(): bool
    {
        $count = count($this->pieces());
        if ($count === 2) {
            return true;
        } elseif ($count === 3) {
            foreach ($this->pieces() as $piece) {
                if ($piece->id === Piece::N) {
                    return true;
                } elseif ($piece->id === Piece::B) {
                    return true;
                }
            }
        } elseif ($count === 4) {
            $colors = '';
            foreach ($this->pieces() as $piece) {
                if ($piece->id === Piece::B) {
                    $colors .= $this->square->color($piece->sq);
                }
            }
            return $colors === Color::W . Color::W || $colors === Color::B . Color::B;
        }

        return false;
    }

    public function legal(string $sq): array
    {
        $legal = [];
        if ($piece = $this->pieceBySq($sq)) {
            foreach ($piece->moveSqs() as $moveSq) {
                $clone = $this->clone();
                if ($piece->id === Piece::K || $piece->id === Piece::P) {
                    if ($clone->playLan($this->turn, "$sq$moveSq")) {
                        $legal[] = $moveSq;
                    }
                } else {
                    if ($clone->play($this->turn, "{$piece->id}{$sq}{$moveSq}")) {
                        $legal[] = $moveSq;
                    } elseif ($clone->play($this->turn, "{$piece->id}{$sq}x{$moveSq}")) {
                        $legal[] = $moveSq;
                    }
                }
            }
        }

        return $legal;
    }

    public function enPassant(): string
    {
        if ($this->history) {
            $last = array_slice($this->history, -1)[0];
            if ($last['move']['id'] === Piece::P) {
                $prevFile = intval(substr($last['sq'], 1));
                $nextFile = intval(substr($last['move']['sq']['next'], 1));
                if ($last['move']['color'] === Color::W) {
                    if ($nextFile - $prevFile === 2) {
                        $rank = $prevFile + 1;
                        return $last['move']['sq']['current'] . $rank;
                    }
                } elseif ($prevFile - $nextFile === 2) {
                    $rank = $prevFile - 1;
                    return $last['move']['sq']['current'] . $rank;
                }
            }
        }

        return '-';
    }

    public function toArray(bool $flip = false): array
    {
        $array = [];
        for ($i = $this->square::SIZE['ranks'] - 1; $i >= 0; $i--) {
            $array[$i] = array_fill(0, $this->square::SIZE['files'], ' . ');
        }
        foreach ($this->pieces() as $piece) {
            list($file, $rank) = $this->square->toIndex($piece->sq);
            if ($flip) {
                $diff = $this->square::SIZE['files'] - $this->square::SIZE['ranks'];
                $file = $this->square::SIZE['files'] - 1 - $file - $diff;
                $rank = $this->square::SIZE['ranks'] - 1 - $rank + $diff;
            }
            $piece->color === Color::W
                ? $array[$file][$rank] = ' ' . $piece->id . ' '
                : $array[$file][$rank] = ' ' . strtolower($piece->id) . ' ';
        }

        return $array;
    }

    public function toString(bool $flip = false): string
    {
        $ascii = '';
        $array = $this->toArray($flip);
        foreach ($array as $i => $rank) {
            foreach ($rank as $j => $file) {
                $ascii .= $array[$i][$j];
            }
            $ascii .= PHP_EOL;
        }

        return $ascii;
    }

    public function toFen(): string
    {
        $string = '';
        $array = $this->toArray();
        for ($i = $this->square::SIZE['ranks'] - 1; $i >= 0; $i--) {
            $string .= str_replace(' ', '', implode('', $array[$i]));
            if ($i != 0) {
                $string .= '/';
            }
        }

        $filtered = '';
        $strSplit = str_split($string);
        $n = 1;
        for ($i = 0; $i < count($strSplit); $i++) {
            if ($strSplit[$i] === '.') {
                if (isset($strSplit[$i + 1]) && $strSplit[$i + 1] === '.') {
                    $n++;
                } else {
                    $filtered .= $n;
                    $n = 1;
                }
            } else {
                $filtered .= $strSplit[$i];
                $n = 1;
            }
        }

        return "{$filtered} {$this->turn} {$this->castlingAbility} {$this->enPassant()}";
    }

    public function diffPieces(array $array1, array $array2): array
    {
        return array_udiff($array2, $array1, function ($b, $a) {
            return $a->sq <=> $b->sq;
        });
    }

    public function doesDraw(): bool
    {
        return false;
    }

    public function doesWin(): bool
    {
        return false;
    }

    public function clone(): AbstractBoard
    {
        $board = FenToBoardFactory::create($this->toFen(), $this);
        $board->captures = $this->captures;
        $board->history = $this->history;
        $board->startFen = $this->startFen;

        return $board;
    }
}
