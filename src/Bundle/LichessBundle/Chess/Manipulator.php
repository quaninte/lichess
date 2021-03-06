<?php

namespace Bundle\LichessBundle\Chess;

use Bundle\LichessBundle\Document\Piece;
use Bundle\LichessBundle\Document\Piece\King;
use Bundle\LichessBundle\Document\Piece\Pawn;
use Bundle\LichessBundle\Chess\Analyser;
use Bundle\LichessBundle\Notation\PgnDumper;
use ArrayObject;
use Bundle\LichessBundle\Document\Game;

class Manipulator
{
    /**
     * The board to manipulate
     *
     * @var Board
     */
    protected $board;

    /**
     * The event stack to record chess events
     *
     * @var ArrayObject
     */
    protected $events;

    protected $game;
    protected $autodraw;
    protected $analyser;

    public function __construct(Game $game, Autodraw $autodraw, Analyser $analyser, ArrayObject $events)
    {
        $this->game     = $game;
        $this->board    = $game->getBoard();
        $this->autodraw = $autodraw;
        $this->analyser = $analyser;
        $this->events    = $events;
    }

    public function play($notation, array $options = array())
    {
        $pgn = $this->move($notation, $options);

        $player = $this->game->getTurnPlayer();
        $opponent = $player->getOpponent();
        $isOpponentKingAttacked = $this->analyser->isKingAttacked($opponent);
        if($isOpponentKingAttacked) {
            $this->events->append(array(
                'type' => 'check',
                'key'  => $opponent->getKing()->getSquareKey()
            ));
            $pgn .= '+';
        }
        $this->game->addTurn();
        if($this->game->hasClock()) {
            if(1 === $this->game->getTurns()) {
                $this->game->getClock()->setColor($this->game->getTurnColor());
                $this->game->getClock()->start();
            }
            else {
                $this->game->getClock()->step();
            }
        }
        $opponentPossibleMoves = $this->analyser->getPlayerPossibleMoves($opponent, $isOpponentKingAttacked);
        if(empty($opponentPossibleMoves)) {
            if($isOpponentKingAttacked) {
                $this->game->setStatus(Game::MATE);
                $this->game->setWinner($player);
                $pgn = preg_replace('/\+$/', '#', $pgn);
            }
            else {
                $this->game->setStatus(Game::STALEMATE);
            }
            $this->events->append(array('type' => 'end'));
        }
        elseif($this->autodraw->isAutodraw($this->game)) {
            $this->game->setStatus(GAME::DRAW);
            $this->events->append(array('type' => 'end'));
        }

        $this->game->addPositionHash();
        if($this->game->isThreefoldRepetition()) {
            $this->events->append(array('type' => 'threefold_repetition'));
        }

        $this->game->addPgnMove($pgn);
        $this->game->setLastMove($notation);

        if ($this->game->getIsRated()) {
            $player->saveMoveTime();
        }

        return $opponentPossibleMoves;
    }

    /**
     * Move a piece on the board
     * Performs several validation before applying the move
     *
     * @param mixed $notation Valid algebraic notation (e.g. "a2 a4")
     * @return string PGN notation of the move
     */
    public function move($notation, array $options = array())
    {
        list($fromKey, $toKey) = explode(' ', $notation);

        if(!$fromKey || !$toKey) {
            throw new \InvalidArgumentException(sprintf('Manipulator:move game:%s, Invalid internal move notation "%s"', $this->game->getId(), $notation));
        }
        if(!$from = $this->board->getSquareByKey($fromKey)) {
            throw new \InvalidArgumentException(sprintf('Manipulator:move game:%s, Square '.$fromKey.' does not exist', $this->game->getId()));
        }
        if(!$to = $this->board->getSquareByKey($toKey)) {
            throw new \InvalidArgumentException(sprintf('Manipulator:move game:%s, Square '.$toKey.' does not exist', $this->game->getId()));
        }
        if(!$piece = $from->getPiece()) {
            throw new \InvalidArgumentException(sprintf('Manipulator:move game:%s, No piece on '.$from, $this->game->getId()));
        }
        $player = $piece->getPlayer();
        if(!$player->isMyTurn()) {
            throw new \LogicException(sprintf('Manipulator:move game:%s, Can not play '.$from.' '.$to.' - Not '.$piece->getColor().' player turn', $this->game->getId()));
        }

        $pieceClass = $piece->getClass();
        $isPlayerKingAttacked = $this->analyser->isKingAttacked($player);
        $playerPossibleMoves = $this->analyser->getPlayerPossibleMoves($player, $isPlayerKingAttacked);
        $possibleMoves = isset($playerPossibleMoves[$fromKey]) ? $playerPossibleMoves[$fromKey] : false;

        if(!$possibleMoves) {
            throw new \LogicException(sprintf('Manipulator:move game:%s, %s can not move', $this->game->getId(), $piece));
        }

        if(!in_array($toKey, $possibleMoves)) {
            throw new \LogicException(sprintf('Manipulator:move game:%s, %s can not go to '.$to.' ('.implode(',', $possibleMoves).')', $this->game->getId(), $piece));
        }

        // killed?
        $killed = $to->getPiece();

        // castling?
        $isCastling = false;
        if('King' === $pieceClass) {
            // standard castling
            if(1 < abs($from->getX() - $to->getX())) {
                $isCastling = true;
            }
            // in case of chess variant, castling can be done by bringing the king to the rook
            elseif($killed && $killed->getColor() === $piece->getColor()) {
                $isCastling = true;
                $killed = null;
            }
        }

        // promotion?
        if('Pawn' === $pieceClass && ($to->getY() === ($player->isWhite() ? 8 : 1))) {
            $isPromotion = true;
            $promotionClass = isset($options['promotion']) ? ucfirst($options['promotion']) : 'Queen';
            if(!in_array($promotionClass, array('Queen', 'Knight', 'Bishop', 'Rook'))) {
                throw new \InvalidArgumentException(sprintf('Manipulator:move game:%s, Bad promotion class: '.$promotionClass, $this->game->getId()));
            }
            $options['promotion'] = $promotionClass;
        }
        else {
            $isPromotion = false;
        }

        // enpassant?
        $isEnPassant = 'Pawn' === $pieceClass && $to->getX() !== $from->getX() && !$killed;

        $pgnDumper = new PgnDumper();
        $pgn = $pgnDumper->dumpMove($this->game, $piece, $from, $to, $playerPossibleMoves, $killed, $isCastling, $isPromotion, $isEnPassant, $options);

        $this->events->append(array(
            'type'  => 'move',
            'from'  => $from->getKey(),
            'to'    => $to->getKey(),
            'color' => $piece->getColor()
        ));

        if($isCastling) {
            $this->castle($piece, $to);
        }
        else {
            if($killed) {
                $killed->setIsDead(true);
                $this->board->remove($killed);
            }
            $this->board->move($piece, $to->getX(), $to->getY());
            if(null === $piece->getFirstMove()) {
                $piece->setFirstMove($this->game->getTurns());
            }
        }

        if($isPromotion) {
            $this->promotion($piece, $options['promotion']);
        }

        if($isEnPassant) {
            $this->enpassant($piece, $to);
        }

        // When an irreversible event happens,
        // we can safely clear the game position hashes
        if($killed || $isPromotion || $isCastling || 'Pawn' === $pieceClass) {
            $this->game->clearPositionHashes();
        }

        return $pgn;
    }

    /**
     * Handle pawn enpassant
     **/
    protected function enpassant(Pawn $pawn, Square $to)
    {
        $passedSquare = $to->getSquareByRelativePos(0, $pawn->getPlayer()->isWhite() ? -1 : 1);
        $killed = $passedSquare->getPiece();

        if(!$killed || $killed->getPlayer() === $pawn->getPlayer()) {
            throw new \LogicException(sprintf('Manipulator:move game:%s, Can not enpassant to '.$to, $this->game->getId()));
        }

        $killed->setIsDead(true);
        $this->board->remove($killed);

        $this->events->append(array(
            'type' => 'enpassant',
            'killed' => $passedSquare->getKey()
        ));
    }

    /**
     * Handle pawn promotion
     **/
    protected function promotion(Pawn $pawn, $promotionClass)
    {
        $player = $pawn->getPlayer();

        $this->board->remove($pawn);
        $player->removePiece($pawn);

        $fullClass = 'Bundle\\LichessBundle\\Document\\Piece\\'.ucfirst($promotionClass);
        $new = new $fullClass($pawn->getX(), $pawn->getY());
        $new->setBoard($player->getGame()->getBoard());
        $player->addPiece($new);
        $this->board->add($new);

        $this->events->append(array(
            'type' => 'promotion',
            'pieceClass' => strtolower($promotionClass),
            'key' => $new->getSquareKey()
        ));
    }

    protected function isCastleKingSide(King $king, Square $to)
    {
        if($to->getPiece()) {
            return $to->getX() > $king->getX();
        }
        else {
            return 7 === $to->getX();
        }
    }

    /**
     * Handle castling
     **/
    protected function castle(King $king, Square $to)
    {
        $isKingSide = $this->isCastleKingSide($king, $to);
        $y = $king->getY();

        if ($isKingSide)
        {
            $rook = $this->analyser->getCastleRookKingSide($king->getPlayer());
            $newRookSquare = $this->board->getSquareByPos(6, $y);
            $newKingSquare = $this->board->getSquareByPos(7, $y);
        }
        else
        {
            $rook = $this->analyser->getCastleRookQueenSide($king->getPlayer());
            $newRookSquare = $this->board->getSquareByPos(4, $y);
            $newKingSquare = $this->board->getSquareByPos(3, $y);
        }
        if(!$rook) {
            throw new \LogicException(sprintf('No rook for castle on %s side, king %s to %s', $isKingSide ? 'King' : 'Queen', $king->getSquareKey(), $to->getKey()));
        }
        $kingSquare = $king->getSquare();
        $rookSquare = $rook->getSquare();

        $this->board->castle($king, $rook, $newKingSquare->getX(), $newRookSquare->getX());
        $king->setFirstMove($this->game->getTurns());
        $rook->setFirstMove($this->game->getTurns());

        $this->events->append(array(
            'type' => 'castling',
            'king' => array($kingSquare->getKey(), $newKingSquare->getKey()),
            'rook' => array($rookSquare->getKey(), $newRookSquare->getKey()),
            'color' => $king->getColor()
        ));
    }

    public function getEvents()
    {
        return $this->events;
    }
}
