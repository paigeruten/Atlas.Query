<?php
namespace Atlas\Query;

use Atlas\Query\QueryTest;
use Generator;
use PDO;
use PDOStatement;

class SelectTest extends QueryTest
{
    public function test__call()
    {
        $this->assertSame([], $this->query->fetchAll());
        $this->assertInstanceOf(Generator::CLASS, $this->query->yieldAll());
        $this->assertInstanceOf(PDOStatement::CLASS, $this->query->perform());

        $this->expectException('BadMethodCallException');
        $this->query->exec();
    }

    public function testDistinct()
    {
        $this->query->distinct()
                     ->from('t1')
                     ->columns('t1.c1', 't1.c2', 't1.c3');

        $actual = $this->query->getStatement();

        $expect = '
            SELECT DISTINCT
                t1.c1,
                t1.c2,
                t1.c3
            FROM
                t1
        ';
        $this->assertSameSql($expect, $actual);
    }

    public function testDuplicateFlag()
    {
        $this->query->distinct()
                    ->distinct()
                    ->from('t1')
                    ->columns('t1.c1', 't1.c2', 't1.c3');

        $actual = $this->query->getStatement();

        $expect = '
            SELECT DISTINCT
                t1.c1,
                t1.c2,
                t1.c3
            FROM
                t1
        ';
        $this->assertSameSql($expect, $actual);
    }

    public function testFlagUnset()
    {
        $this->query->distinct()
                    ->distinct(false)
                    ->from('t1')
                    ->columns('t1.c1', 't1.c2', 't1.c3');

        $actual = $this->query->getStatement();

        $expect = '
            SELECT
                t1.c1,
                t1.c2,
                t1.c3
            FROM
                t1
        ';
        $this->assertSameSql($expect, $actual);
    }

    public function testColumns()
    {
        $this->assertFalse($this->query->hasColumns());

        $this->query->columns(
            't1.c1',
            'c2 AS a2',
            'COUNT(t1.c3)'
        );

        $this->assertTrue($this->query->hasColumns());

        $actual = $this->query->getStatement();
        $expect = '
            SELECT
                t1.c1,
                c2 AS a2,
                COUNT(t1.c3)
        ';
        $this->assertSameSql($expect, $actual);
    }

    public function testFrom()
    {
        $this->query->columns('*');
        $this->query->from('t1')
                    ->from('t2');

        $actual = $this->query->getStatement();
        $expect = '
            SELECT
                *
            FROM
                t1,
                t2
        ';
        $this->assertSameSql($expect, $actual);
    }

    public function testFromSubSelect()
    {
        $this->query
            ->columns('*')
            ->from($this->query->subSelect()
                ->columns('*')
                ->from('t2')
                ->as('a2')
                ->getStatement()
            );

        $expect = '
            SELECT
                *
            FROM
                (
                    SELECT
                        *
                    FROM
                        t2
                ) AS a2
        ';

        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);
    }

    public function testFromSubSelectObject()
    {
        // note that these are "out of order" on purpose,
        // to make sure that sequential binding happens correctly.
        $this->query->columns('*')
            ->where('a2.baz = ', 'dib')
            ->from($this->query->subSelect()
                ->columns('*')
                ->from('t2')
                ->where('foo = ', 'bar')
                ->as('a2')
            );

        $expect = '
            SELECT
                *
            FROM
                (
                    SELECT
                        *
                    FROM
                        t2
                    WHERE
                        foo = :_2_1_
                ) AS a2
            WHERE
                a2.baz = :_1_1_
        ';

        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);

        $expect = [
            '_1_1_' => ['dib', PDO::PARAM_STR],
            '_2_1_' => ['bar', PDO::PARAM_STR]
        ];

        $actual = $this->query->getBindValues();
        $this->assertSame($expect, $actual);
    }

    public function testJoin()
    {
        $this->query->columns('*');
        $this->query->from('t1');
        $this->query->join('left', 't2', 't1.id = t2.id');
        $this->query->join('inner', 't3 AS a3', 't2.id = a3.id');
        $this->query->from('t4');
        $this->query->join('natural', 't5');
        $expect = '
            SELECT
                *
            FROM
                t1
                    LEFT JOIN t2 ON t1.id = t2.id
                    INNER JOIN t3 AS a3 ON t2.id = a3.id,
                t4
                    NATURAL JOIN t5
        ';
        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);
    }

    public function testJoinAndBind()
    {
        $this->query->columns('*');
        $this->query->from('t1');
        $this->query->join(
            'left',
            't2',
            't1.id = t2.id AND t1.foo = ',
            'bar'
        );
        $this->query->catJoin(' AND t1.baz = ', 'dib');

        $expect = '
            SELECT
                *
            FROM
                t1
            LEFT JOIN t2 ON t1.id = t2.id AND t1.foo = :_1_1_ AND t1.baz = :_1_2_
        ';
        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);

        $expect = [
            '_1_1_' => ['bar', PDO::PARAM_STR],
            '_1_2_' => ['dib', PDO::PARAM_STR]
        ];
        $actual = $this->query->getBindValues();
        $this->assertSame($expect, $actual);
    }

    public function testJoinSubSelect()
    {
        $sub1 = '(SELECT * FROM t2) AS a2';
        $sub2 = '(SELECT * FROM t3) AS a3';
        $this->query->columns('*');
        $this->query->from('t1');
        $this->query->join('left', $sub1, 't2.c1 = a3.c1');
        $this->query->join('natural', $sub2);
        $expect = '
            SELECT
                *
            FROM
                t1
                    LEFT JOIN (SELECT * FROM t2) AS a2 ON t2.c1 = a3.c1
                    NATURAL JOIN (SELECT * FROM t3) AS a3
        ';
        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);
    }

    public function testJoinSubSelectObject()
    {
        $sub = $this->query->subSelect();
        $sub->columns('*')->from('t2')->where('foo = ', 'bar')->as('a3');

        $this->query->columns('*');
        $this->query->from('t1');
        $this->query->join('left', $sub, 't2.c1 = a3.c1');
        $this->query->where('baz = ', 'dib');

        $expect = '
            SELECT
                *
            FROM
                t1
                    LEFT JOIN (
                        SELECT
                            *
                        FROM
                            t2
                        WHERE
                            foo = :_2_1_
                    ) AS a3 ON t2.c1 = a3.c1
            WHERE
                baz = :_1_1_
        ';
        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);
    }

    public function testJoinOrder()
    {
        $this->query
            ->columns('*')
            ->from('t1')
            ->join('inner', 't2', 't2.id = t1.id')
            ->join('left', 't3', 't3.id = t2.id')
            ->from('t4')
            ->join('inner', 't5', 't5.id = t4.id');

        $expect = '
            SELECT
                *
            FROM
                t1
                    INNER JOIN t2 ON t2.id = t1.id
                    LEFT JOIN t3 ON t3.id = t2.id,
                t4
                    INNER JOIN t5 ON t5.id = t4.id
        ';

        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);
    }

    public function testJoinOnAndUsing()
    {
        $this->query
            ->columns('*')
            ->from('t1')
            ->join('inner', 't2', 'ON t2.id = t1.id')
            ->join('left', 't3', 'USING (id)');

        $expect = '
            SELECT
                *
            FROM
                t1
                    INNER JOIN t2 ON t2.id = t1.id
                    LEFT JOIN t3 USING (id)
        ';
        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);
    }

    public function testWhere()
    {
        $this->query
            ->columns('*')
            ->where('c1 = c2')
            ->andWhere('c3 = :c3')
            ->andWhere('c4 IN', [null, true, 1])
            ->catWhere(' AND c5 = ' . $this->query->bindInline(2))
            ->bindValue('c3', 'foo');

        $expect = '
            SELECT
                *
            WHERE
                c1 = c2
                AND c3 = :c3
                AND c4 IN(:_1_1_, :_1_2_, :_1_3_) AND c5 = :_1_4_
        ';

        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);

        $actual = $this->query->getBindValues();
        $expect = [
            '_1_1_' => [null, PDO::PARAM_NULL],
            '_1_2_' => [true, PDO::PARAM_BOOL],
            '_1_3_' => [1, PDO::PARAM_INT],
            '_1_4_' => [2, PDO::PARAM_INT],
            'c3' => ['foo', PDO::PARAM_STR],
        ];
        $this->assertSame($expect, $actual);
    }

    public function testOrWhere()
    {
        $this->query
            ->columns('*')
            ->catWhere('c1 = ', 'bar')
            ->orWhere('c3 = :c3')
            ->bindValue('c3', 'foo');

        $expect = '
            SELECT
                *
            WHERE
                c1 = :_1_1_
                OR c3 = :c3
        ';

        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);

        $actual = $this->query->getBindValues();
        $expect = [
            '_1_1_' => ['bar', PDO::PARAM_STR],
            'c3' => ['foo', PDO::PARAM_STR]
        ];
        $this->assertSame($expect, $actual);
    }

    public function testWhereEquals()
    {
        $actual = $this->query
            ->columns('*')
            ->whereEquals([
                'foo' => [1, 2, 3],
                'bar' => null,
                'baz' => 'baz_value',
                'dib = NOW()',
            ]);

        $expect = '
            SELECT
                *
            WHERE
                foo IN (:_1_1_, :_1_2_, :_1_3_)
                AND bar IS NULL
                AND baz = :_1_4_
                AND dib = NOW()
        ';

        $this->assertSameSql($expect, $actual->getStatement());
    }

    public function testWhereSprintf()
    {
        $actual = $this->query
            ->columns('*')
            ->whereSprintf('c1 BETWEEN %s AND %s', 11, 22)
            ->andWhereSprintf('c2 BETWEEN %s AND %s', 33, 44)
            ->orWhereSprintf('c3 BETWEEN %s AND %s', 55, 66)
            ->catWhereSprintf(' UNLESS c4 BETWEEN %s AND %s', 77, 88);

        $expect = '
            SELECT
                *
            WHERE
                c1 BETWEEN :_1_1_ AND :_1_2_
                AND c2 BETWEEN :_1_3_ AND :_1_4_
                OR c3 BETWEEN :_1_5_ AND :_1_6_ UNLESS c4 BETWEEN :_1_7_ AND :_1_8_
        ';

        $this->assertSameSql($expect, $actual->getStatement());
    }

    public function testGroupBy()
    {
        $this->query
            ->columns('*')
            ->groupBy('c1')
            ->groupBy('t2.c2');

        $expect = '
            SELECT
                *
            GROUP BY
                c1,
                t2.c2
        ';

        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);
    }

    public function testHaving()
    {
        $this->query
            ->columns('*')
            ->having('c1 = c2')
            ->andHaving('c3 = :c3')
            ->orHaving('(c4 = 1 ')
            ->catHaving('AND c5 = 2)')
            ->bindValue('c3', 'foo');

        $expect = '
            SELECT
                *
            HAVING
                c1 = c2
                AND c3 = :c3
                OR (c4 = 1 AND c5 = 2)
        ';

        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);

        $actual = $this->query->getBindValues();
        $expect = [
            'c3' => ['foo', PDO::PARAM_STR]
        ];
        $this->assertSame($expect, $actual);
    }

    public function testOrHaving()
    {
        $this->query
            ->columns('*')
            ->orHaving('c1 = c2')
            ->orHaving('c3 = :c3')
            ->bindValue('c3', 'foo');

        $expect = '
            SELECT
                *
            HAVING
                c1 = c2
                OR c3 = :c3
        ';

        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);

        $actual = $this->query->getBindValues();
        $expect = [
            'c3' => ['foo', PDO::PARAM_STR],
        ];
        $this->assertSame($expect, $actual);
    }

    public function testHavingSprintf()
    {
        $actual = $this->query
            ->columns('*')
            ->HavingSprintf('c1 BETWEEN %s AND %s', 11, 22)
            ->andHavingSprintf('c2 BETWEEN %s AND %s', 33, 44)
            ->orHavingSprintf('c3 BETWEEN %s AND %s', 55, 66)
            ->catHavingSprintf(' UNLESS c4 BETWEEN %s AND %s', 77, 88);

        $expect = '
            SELECT
                *
            HAVING
                c1 BETWEEN :_1_1_ AND :_1_2_
                AND c2 BETWEEN :_1_3_ AND :_1_4_
                OR c3 BETWEEN :_1_5_ AND :_1_6_ UNLESS c4 BETWEEN :_1_7_ AND :_1_8_
        ';

        $this->assertSameSql($expect, $actual->getStatement());
    }

    public function testOrderBy()
    {
        $this->query
            ->columns('*')
            ->orderBy('c1', 'UPPER(t2.c2)');

        $expect = '
            SELECT
                *
            ORDER BY
                c1,
                UPPER(t2.c2)
        ';

        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);
    }

    public function testLimitOffset()
    {
        $this->query->columns('*');
        $this->query->limit(10);
        $expect = '
            SELECT
                *
            LIMIT 10
        ';
        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);

        $this->query->offset(40);
        $expect = '
            SELECT
                *
            LIMIT 10 OFFSET 40
        ';
        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);
    }

    public function testPage()
    {
        $this->query->columns('*');
        $this->query->page(5);
        $expect = '
            SELECT
                *
            LIMIT 10 OFFSET 40
        ';
        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);

        $this->query->perPage(25);
        $expect = '
            SELECT
                *
            LIMIT 25 OFFSET 100
        ';
        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);
    }

    public function testForUpdate()
    {
        $this->query->columns('*');
        $this->query->forUpdate();
        $expect = '
            SELECT
                *
            FOR UPDATE
        ';
        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);
    }

    public function testUnion()
    {
        $this->query->columns('c1')
                     ->from('t1')
                     ->union()
                     ->columns('c2')
                     ->from('t2')
                     ->union()
                     ->columns('c3')
                     ->from('t3')
                     ->union()
                     ->columns('c4')
                     ->from('t4');

        $expect = '
            SELECT
                c1
            FROM
                t1
            UNION
            SELECT
                c2
            FROM
                t2
            UNION
            SELECT
                c3
            FROM
                t3
            UNION
            SELECT
                c4
            FROM
                t4
        ';

        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);
    }

    public function testUnionAll()
    {
        $this->query->columns('c1')
                     ->from('t1')
                     ->unionAll()
                     ->columns('c2')
                     ->from('t2')
                     ->unionAll()
                     ->columns('c3')
                     ->from('t3')
                     ->unionAll()
                     ->columns('c4')
                     ->from('t4');

        $expect = '
            SELECT
                c1
            FROM
                t1
            UNION ALL
            SELECT
                c2
            FROM
                t2
            UNION ALL
            SELECT
                c3
            FROM
                t3
            UNION ALL
            SELECT
                c4
            FROM
                t4
        ';

        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);
    }

    public function testWhereSubSelect()
    {
        $select = $this->newQuery();
        $select->columns('*')
            ->from('table2 AS t2')
            ->where('field IN ', $select->subSelect()
                ->columns('col1')
                ->from('table1 AS t1')
            );

        $expect = '
            SELECT
                *
            FROM
                table2 AS t2
            WHERE
                field IN (SELECT
                col1
            FROM
                table1 AS t1)
        ';
        $actual = $select->getStatement();
        $this->assertSameSql($expect, $actual);
    }

    public function testIssue49()
    {
        $limit = new \Atlas\Query\Clause\Component\Limit();

        $this->assertSame(0, $limit->getPage());
        $this->assertSame(10, $limit->getPerPage());
        $this->assertSame(0, $limit->getLimit());
        $this->assertSame(0, $limit->getOffset());

        $limit->setPage(3);
        $this->assertSame(3, $limit->getPage());
        $this->assertSame(10, $limit->getPerPage());
        $this->assertSame(10, $limit->getLimit());
        $this->assertSame(20, $limit->getOffset());

        $limit->setLimit(10);
        $this->assertSame(0, $limit->getPage());
        $this->assertSame(10, $limit->getPerPage());
        $this->assertSame(10, $limit->getLimit());
        $this->assertSame(0, $limit->getOffset());

        $limit->setPage(3);
        $limit->setPerPage(50);
        $this->assertSame(3, $limit->getPage());
        $this->assertSame(50, $limit->getPerPage());
        $this->assertSame(50, $limit->getLimit());
        $this->assertSame(100, $limit->getOffset());

        $limit->setOffset(10);
        $this->assertSame(0, $limit->getPage());
        $this->assertSame(50, $limit->getPerPage());
        $this->assertSame(0, $limit->getLimit());
        $this->assertSame(10, $limit->getOffset());
    }

    public function testAs()
    {
        $this->query->columns('*')->from('t1')->as('foo');
        $expect = '
            (
                SELECT
                    *
                FROM
                    t1
            ) AS foo
        ';
        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);
    }

    public function test__clone()
    {
        $query = new FakeSelect($this->connection, $this->driver);
        $clone = clone $query;

        $vars = ['bind', 'flags', 'columns', 'from', 'where', 'groupBy', 'having', 'orderBy', 'limit'];
        foreach ($vars as $var) {
            $this->assertNotSame($query->$var, $clone->$var);
        }
    }

    public function testUnionSelectCanHaveSameAliasesInDifferentSelects()
    {
        $select = $this->query
            ->columns(
                '...'
            )
            ->from('a')
            ->join('INNER', 'c', 'a_cid = c_id')
            ->union()
            ->columns(
                '...'
            )
            ->from('b')
            ->join('INNER', 'c', 'b_cid = c_id');

        $expected = 'SELECT
                    ...
                    FROM
                    a
                    INNER JOIN c ON a_cid = c_id
                    UNION
                    SELECT
                    ...
                    FROM
                    b
                    INNER JOIN c ON b_cid = c_id';

        $actual = (string) $select->getStatement();
        $this->assertSameSql($expected, $actual);
    }

    public function testQuoteIdentifier()
    {
        $actual = $this->query->quoteIdentifier('foo');
        $this->assertSame('<<foo>>', $actual);
    }

    public function testSetFlag()
    {
        $this->query->columns('*')->from('t1')->setFlag('LOW_PRIORITY');
        $expect = '
            SELECT LOW_PRIORITY
                *
            FROM
                t1
        ';
        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);
    }

    public function testSqlsrvLimitOffset()
    {
        $this->query = Select::query('sqlsrv');

        $this->query->columns('*');
        $this->query->limit(10);
        $expect = '
            SELECT TOP 10
                *
        ';
        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);

        $this->query->offset(40);
        $expect = '
            SELECT
                *
            OFFSET 40 ROWS FETCH NEXT 10 ROWS ONLY
        ';
        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);
    }

    public function testSqlsrvPage()
    {
        $this->query = Select::query('sqlsrv');

        $this->query->columns('*');
        $this->query->page(5);
        $expect = '
            SELECT
                *
            OFFSET 40 ROWS FETCH NEXT 10 ROWS ONLY
        ';
        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);
    }

    public function testBindSprintf()
    {
        $this->query->columns('*')
                    ->from('t1')
                    ->where($this->query->bindSprintf(
                        'c2 BETWEEN %s AND %s',
                        6,
                        9
                    ));

        $expect = '
            SELECT
                *
            FROM
                t1
            WHERE
                c2 BETWEEN :_1_1_ AND :_1_2_
        ';
        $actual = $this->query->getStatement();
        $this->assertSameSql($expect, $actual);

        $expect = [
            '_1_1_' => [6, PDO::PARAM_INT],
            '_1_2_' => [9, PDO::PARAM_INT],
        ];
        $actual = $this->query->getBindValues();
        $this->assertSame($expect, $actual);
    }

    public function testWith()
    {
        $this->query
            ->with('cte1', 'SELECT dib, zim FROM gir')
            ->withColumns('cte2', ['foo', 'bar'], 'SELECT * FROM baz')
            ->columns('*')
            ->from('cte1')
            ->union()
            ->columns('*')
            ->from('cte2');

        $actual = $this->query->getStatement();

        $expect = '
            WITH
                <<cte1>> AS (
                    SELECT dib, zim FROM gir
                ),
                <<cte2>> (<<foo>>, <<bar>>) AS (
                    SELECT * FROM baz
                )
            SELECT
                *
            FROM
                cte1
            UNION
            SELECT
                *
            FROM
                cte2
        ';

        $this->assertSameSql($expect, $actual);
    }

    public function testWithQueryObject()
    {
        $cte1 = $this->newQuery()
            ->columns('*')
            ->from('baz')
            ->where('c1 = ', 'v1');

        $cte2 = $this->newQuery()
            ->columns('dib', 'zim')
            ->from('gir')
            ->where('c2 = ', 'v2');

        $this->query
            ->with('cte1', $cte2)
            ->withColumns('cte2', ['foo', 'bar'], $cte1)
            ->columns('*')
            ->from('cte1')
            ->union()
            ->columns('*')
            ->from('cte2');

        $actual = $this->query->getStatement();

        $expect = '
        WITH
            <<cte1>> AS (
                SELECT
                    dib,
                    zim
                FROM
                    gir
                WHERE
                    c2 = :_3_1_
            ),
            <<cte2>> (<<foo>>, <<bar>>) AS (
                SELECT
                *
                FROM
                    baz
                WHERE
                    c1 = :_2_1_
            )
        SELECT
            *
        FROM
            cte1
        UNION
        SELECT
            *
        FROM
            cte2
        ';

        $this->assertSameSql($expect, $actual);

        $expect = [
            '_3_1_' => [
                0 => 'v2',
                1 => 2,
            ],
            '_2_1_' => [
                0 => 'v1',
                1 => 2,
            ],
        ];

        $actual = $this->query->getBindValues();
        $this->assertSame($expect, $actual);
    }

    public function testWithRecursive()
    {
        $this->query
            ->withRecursive()
            ->with('cte1', 'SELECT dib, zim FROM gir')
            ->withColumns('cte2', ['foo', 'bar'], 'SELECT * FROM baz')
            ->columns('*')
            ->from('cte1')
            ->union()
            ->columns('*')
            ->from('cte2');

        $actual = $this->query->getStatement();

        $expect = '
            WITH RECURSIVE
                <<cte1>> AS (
                    SELECT dib, zim FROM gir
                ),
                <<cte2>> (<<foo>>, <<bar>>) AS (
                    SELECT * FROM baz
                )
            SELECT
                *
            FROM
                cte1
            UNION
            SELECT
                *
            FROM
                cte2
        ';

        $this->assertSameSql($expect, $actual);
    }
}
